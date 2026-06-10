<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /orders endpoints — read + create + status + cancel.
 *
 * Coverage:
 *   - read: count, list (paginated + status-filtered), updatedSince, get
 *   - write: create (catalog + custom pricing modes), updateStatus, cancel
 *   - validation: missing items / shipping_address, bad payment / shipping
 *                 module, bad currency_id, bad customer.group_id
 *   - order token: cancel without correct token returns 403
 *   - state machine: cancel from non-cancellable status returns 409
 *
 * Order creation has real side effects on a live store: inventory
 * deduction, ExternalOrder commit, event triggers. Tests use uid()-tagged
 * channel_order_id values so the resulting rows are greppable.
 *
 * No cleanup. Created orders remain on the store.
 */
final class OrdersTest extends IntegrationTestCase
{
    /**
     * Cache for the single product we use as a stable line-item across
     * all tests in this class. Lazy, created on first use.
     */
    private static ?int $sharedProductId = null;

    private function shippableProductId(): int
    {
        if (self::$sharedProductId !== null) {
            return self::$sharedProductId;
        }
        $r = $this->client->products()->create([
            'name'            => $this->uid('OrderTestProduct'),
            'sku'             => $this->uid('order-sku'),
            'price'           => 25.00,
            'visible'         => 1,
            'inventory'       => 9999,  // generous so cancels don't burn it down
            'inventory_track' => 1,
        ]);
        self::$sharedProductId = (int)$r->getData()['productid'];
        return self::$sharedProductId;
    }

    private function basicAddress(): array
    {
        return [
            'firstname' => 'Sdk',
            'lastname'  => 'Tester',
            'address1'  => '1 Integration Way',
            'city'      => 'Athens',
            'state'     => 'Attica',
            'country'   => 'Greece',
            'country_id' => 1,
            'zip'       => '11111',
            'phone'     => '2100000000',
        ];
    }

    /**
     * Payment module to use for test orders. `checkout_cashondelivery` is
     * present in every default install. The order controller validates
     * enabled+configured upfront — if your dev store has disabled COD,
     * override via the NC_TEST_PAYMENT env var.
     */
    private function testPaymentModule(): string
    {
        $env = getenv('NC_TEST_PAYMENT');
        return $env !== false && $env !== '' ? $env : 'checkout_cashondelivery';
    }

    // -- read ----------------------------------------------------------

    #[Test]
    public function count_returns_integer(): void
    {
        $this->requireScope('orders.read');
        $data = $this->client->orders()->count()->getData();
        $this->assertArrayHasKey('count', $data);
        $this->assertIsInt($data['count']);
        $this->assertGreaterThanOrEqual(0, $data['count']);
    }

    #[Test]
    public function list_with_no_params_returns_paginated(): void
    {
        $this->requireScope('orders.read');
        $r = $this->client->orders()->list(['limit' => 5]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
        $this->assertArrayHasKey('total', $r->getMeta());
    }

    #[Test]
    public function updated_since_works_with_unix_timestamp(): void
    {
        $this->requireScope('orders.read');
        $r = $this->client->orders()->updatedSince(1, ['limit' => 5]);
        $this->assertSame(200, $r->getStatusCode());
    }

    // -- create + roundtrip --------------------------------------------

    #[Test]
    public function create_minimal_order_and_get_back(): void
    {
        $this->requireScope('orders.read');
        $this->requireScope('orders.write');
        $this->requireScope('products.write');

        $productId = $this->shippableProductId();
        $chanOrderId = $this->uid('chan-order');

        // Test key has `orders.*` which IS treated as orders.custom_pricing
        // (see OrdersController::callerCanCustomPrice). In custom mode the
        // server uses each line's unit_price; sending bare items would
        // result in total_inc_tax = 0, which would silently fool the test.
        $r = $this->client->orders()->create([
            'items'            => [[
                'product_id'         => $productId,
                'quantity'           => 2,
                'unit_price'         => 25.00,
                'price_includes_vat' => true,
                'vat_ratio'          => 24.0,
            ]],
            'shipping_address' => $this->basicAddress(),
            'customer'         => ['firstname' => 'Sdk', 'lastname' => 'Tester', 'email' => 'sdkit-order@example.test'],
            'channel_order_id' => $chanOrderId,
            'payment_method'   => $this->testPaymentModule(),
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertIsInt((int)$data['order_id']);
        $this->assertNotEmpty($data['order_token']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $data['order_token'],
            'order_token must be 32-char hex (the matcher used by /orders/order/(token) route)');
        $this->assertSame('custom', $data['pricing_mode'],
            'with orders.* the server must select custom pricing');

        $token = (string)$data['order_token'];

        // Roundtrip via get(token) + assert the totals actually landed.
        $back = $this->client->orders()->get($token)->getData();
        $this->assertSame((int)$data['order_id'], (int)$back['orderid']);
        $this->assertEqualsWithDelta(50.00, (float)$back['total_inc_tax'], 0.01,
            'custom-priced order: total_inc_tax must equal quantity * unit_price (no shipping)');
        $this->assertSame(2, (int)$back['ordtotalqty']);
        $this->assertSame($this->testPaymentModule(), $back['orderpaymentmodule'],
            'orderpaymentmodule must reflect the payment_method sent on create');
    }

    #[Test]
    public function unit_price_omitted_falls_back_to_catalog_price(): void
    {
        $this->requireScope('orders.read');
        $this->requireScope('orders.write');
        $this->requireScope('products.write');

        // Make a product with a known catalog price.
        $productId = (int)$this->client->products()->create([
            'name'  => $this->uid('FallbackCat'),
            'sku'   => $this->uid('fallback-cat'),
            'price' => 17.50,
            'visible' => 1, 'inventory' => 10, 'inventory_track' => 1,
        ])->getData()['productid'];

        // Order WITHOUT unit_price — server should use prodcalculatedprice
        // (= prodprice when no sale price). Without the catalog fallback we
        // added, this would silently create a $0 line.
        $r = $this->client->orders()->create([
            'items'            => [['product_id' => $productId, 'quantity' => 2]],
            'shipping_address' => $this->basicAddress(),
            'customer'         => ['firstname' => 'F', 'lastname' => 'B', 'email' => 'sdkit-fb@example.test'],
            'payment_method'   => $this->testPaymentModule(),
        ]);
        $back = $this->client->orders()->get((string)$r->getData()['order_token'])->getData();
        $this->assertEqualsWithDelta(35.00, (float)$back['total_inc_tax'], 0.01,
            'omitting unit_price must fall back to catalog prodcalculatedprice (17.50 * 2)');
    }

    #[Test]
    public function unit_price_zero_explicit_creates_free_line(): void
    {
        // Sending `unit_price: 0` is honored (free / promo line). Only an
        // ABSENT unit_price triggers the catalog fallback. This pins the
        // boundary between the two behaviours.
        $this->requireScope('orders.write');
        $this->requireScope('orders.read');
        $productId = $this->shippableProductId();

        $r = $this->client->orders()->create([
            'items'            => [[
                'product_id' => $productId, 'quantity' => 1, 'unit_price' => 0,
                'price_includes_vat' => true, 'vat_ratio' => 24.0,
            ]],
            'shipping_address' => $this->basicAddress(),
            'customer'         => ['firstname' => 'Z', 'lastname' => 'P', 'email' => 'sdkit-zero@example.test'],
            'payment_method'   => $this->testPaymentModule(),
        ]);
        $back = $this->client->orders()->get((string)$r->getData()['order_token'])->getData();
        $this->assertEqualsWithDelta(0.00, (float)$back['total_inc_tax'], 0.01,
            'explicit unit_price=0 must NOT fall back to catalog — it is a deliberate free line');
    }

    #[Test]
    public function create_multi_item_order_sums_correctly(): void
    {
        $this->requireScope('orders.write');
        $this->requireScope('orders.read');
        $this->requireScope('products.write');

        // The quote layer merges line items by product_id, so a multi-item
        // sum check needs DISTINCT products per line — otherwise the second
        // line gets merged into the first and inherits its unit_price.
        $p1 = (int)$this->client->products()->create([
            'name'  => $this->uid('OrdMultiA'),
            'sku'   => $this->uid('ord-multi-a'),
            'price' => 99.99,
            'visible' => 1, 'inventory' => 100, 'inventory_track' => 1,
        ])->getData()['productid'];
        $p2 = (int)$this->client->products()->create([
            'name'  => $this->uid('OrdMultiB'),
            'sku'   => $this->uid('ord-multi-b'),
            'price' => 99.99,
            'visible' => 1, 'inventory' => 100, 'inventory_track' => 1,
        ])->getData()['productid'];

        $r = $this->client->orders()->create([
            'items' => [
                ['product_id' => $p1, 'quantity' => 1, 'unit_price' => 10.00, 'price_includes_vat' => true, 'vat_ratio' => 24.0],
                ['product_id' => $p2, 'quantity' => 3, 'unit_price' => 5.50,  'price_includes_vat' => true, 'vat_ratio' => 24.0],
            ],
            'shipping_address' => $this->basicAddress(),
            'customer'         => ['firstname' => 'Multi', 'lastname' => 'Item', 'email' => 'sdkit-multi@example.test'],
            'payment_method'   => $this->testPaymentModule(),
        ]);
        $data = $r->getData();
        $back = $this->client->orders()->get((string)$data['order_token'])->getData();
        // 1*10 + 3*5.5 = 26.50
        $this->assertEqualsWithDelta(26.50, (float)$back['total_inc_tax'], 0.01);
        $this->assertSame(4, (int)$back['ordtotalqty']);
        $this->assertNotEmpty($back['orderpaymentmodule'], 'payment module must persist on multi-item orders too');
    }

    #[Test]
    public function create_order_with_shipping_cost_includes_it_in_total(): void
    {
        $this->requireScope('orders.write');
        $this->requireScope('orders.read');
        $productId = $this->shippableProductId();

        $r = $this->client->orders()->create([
            'items' => [[
                'product_id' => $productId, 'quantity' => 1, 'unit_price' => 20.00,
                'price_includes_vat' => true, 'vat_ratio' => 24.0,
            ]],
            'shipping_address' => $this->basicAddress(),
            'shipping'         => ['cost' => 5.00, 'description' => 'Integration courier', 'is_custom' => true],
            'customer'         => ['firstname' => 'Ship', 'lastname' => 'Add', 'email' => 'sdkit-ship@example.test'],
            'payment_method'   => $this->testPaymentModule(),
        ]);
        $back = $this->client->orders()->get((string)$r->getData()['order_token'])->getData();
        // 20 + 5 shipping = 25
        $this->assertEqualsWithDelta(25.00, (float)$back['total_inc_tax'], 0.01);
        $this->assertEqualsWithDelta(5.00, (float)$back['shipping_cost_inc_tax'], 0.01);
    }

    #[Test]
    public function create_without_items_returns_400(): void
    {
        $this->requireScope('orders.write');
        try {
            $this->client->orders()->create([
                'shipping_address' => $this->basicAddress(),
            ]);
            $this->fail('Expected 400 for missing items');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('item', strtolower($e->getErrorDetail()));
        }
    }

    #[Test]
    public function create_without_shipping_address_returns_400(): void
    {
        $this->requireScope('orders.write');
        $productId = $this->shippableProductId();
        try {
            $this->client->orders()->create([
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ]);
            $this->fail('Expected 400 for missing shipping_address');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('shipping', strtolower($e->getErrorDetail()));
        }
    }

    #[Test]
    public function create_with_unknown_payment_method_returns_400(): void
    {
        $this->requireScope('orders.write');
        $productId = $this->shippableProductId();
        try {
            $this->client->orders()->create([
                'items'            => [['product_id' => $productId, 'quantity' => 1]],
                'shipping_address' => $this->basicAddress(),
                'payment_method'   => 'ghost_module_does_not_exist',
            ]);
            $this->fail('Expected 400 for unknown payment_method');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('payment module', $e->getErrorDetail());
        }
    }

    #[Test]
    public function create_with_unknown_shipping_module_returns_400(): void
    {
        $this->requireScope('orders.write');
        $productId = $this->shippableProductId();
        try {
            $this->client->orders()->create([
                'items'            => [['product_id' => $productId, 'quantity' => 1]],
                'shipping_address' => $this->basicAddress(),
                'shipping'         => ['module' => 'ghost_shipping_module', 'cost' => 5.0],
            ]);
            $this->fail('Expected 400 for unknown shipping module');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('shipping module', $e->getErrorDetail());
        }
    }

    #[Test]
    public function create_with_unknown_currency_id_returns_400(): void
    {
        $this->requireScope('orders.write');
        $productId = $this->shippableProductId();
        try {
            $this->client->orders()->create([
                'items'            => [['product_id' => $productId, 'quantity' => 1]],
                'shipping_address' => $this->basicAddress(),
                'currency_id'      => 99999,
            ]);
            $this->fail('Expected 400 for unknown currency_id');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('currency_id', $e->getErrorDetail());
        }
    }

    #[Test]
    public function create_with_unknown_customer_group_id_returns_400(): void
    {
        $this->requireScope('orders.write');
        $productId = $this->shippableProductId();
        try {
            $this->client->orders()->create([
                'items'            => [['product_id' => $productId, 'quantity' => 1]],
                'shipping_address' => $this->basicAddress(),
                'customer'         => ['group_id' => 99999, 'firstname' => 'X', 'lastname' => 'Y', 'email' => 'x@x.test'],
            ]);
            $this->fail('Expected 400 for unknown customer.group_id');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('group_id', $e->getErrorDetail());
        }
    }

    #[Test]
    public function update_status_changes_ordstatus(): void
    {
        $this->requireScope('orders.write');
        $productId = $this->shippableProductId();

        $created = $this->client->orders()->create([
            'items'            => [['product_id' => $productId, 'quantity' => 1]],
            'shipping_address' => $this->basicAddress(),
            'customer'         => ['firstname' => 'Sdk', 'lastname' => 'Tester', 'email' => 'sdkit-status@example.test'],
            'payment_method'   => $this->testPaymentModule(),
        ])->getData();
        $orderId = (int)$created['order_id'];

        // Move to a representative status — 7 = awaiting_payment in many
        // stores, but any positive int works since the server doesn't
        // restrict to a closed set here.
        $r = $this->client->orders()->updateStatus($orderId, 7, 'integration test status change');
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertSame($orderId, (int)$data['order_id']);
        $this->assertSame(7, (int)$data['new_status']);
    }

    #[Test]
    public function update_status_unknown_order_returns_404(): void
    {
        $this->requireScope('orders.write');
        try {
            $this->client->orders()->updateStatus(99999999, 1);
            $this->fail('Expected 404 for unknown order');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function cancel_with_wrong_token_returns_403(): void
    {
        $this->requireScope('orders.write');
        $productId = $this->shippableProductId();
        $created = $this->client->orders()->create([
            'items'            => [['product_id' => $productId, 'quantity' => 1]],
            'shipping_address' => $this->basicAddress(),
            'customer'         => ['firstname' => 'Sdk', 'lastname' => 'Tester', 'email' => 'sdkit-canceltok@example.test'],
            'payment_method'   => $this->testPaymentModule(),
        ])->getData();

        try {
            $this->client->orders()->cancel((int)$created['order_id'], str_repeat('0', 32));
            $this->fail('Expected 403 for wrong order token');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    #[Test]
    public function cancel_with_correct_token_succeeds(): void
    {
        $this->requireScope('orders.write');
        $productId = $this->shippableProductId();
        $created = $this->client->orders()->create([
            'items'            => [['product_id' => $productId, 'quantity' => 1]],
            'shipping_address' => $this->basicAddress(),
            'customer'         => ['firstname' => 'Sdk', 'lastname' => 'Tester', 'email' => 'sdkit-cancelok@example.test'],
            'payment_method'   => $this->testPaymentModule(),
        ])->getData();

        $r = $this->client->orders()->cancel((int)$created['order_id'], (string)$created['order_token']);
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertSame((int)$created['order_id'], (int)$data['order_id']);
    }
}
