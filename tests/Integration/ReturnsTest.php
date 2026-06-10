<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /returns + /returns/refund/{order_id} surface.
 *
 * Pins:
 *   - Create return with valid order_id round-trips
 *   - Unknown order_id returns 400 (not 500)
 *   - Transitioning status → 5 (closed) bumps orders.ordrefundedamount
 *   - Plain refund updates ordrefundedamount and reports `remaining`
 *   - Over-refunding (amount > balance) returns 409
 *   - listReturns filters by order_id
 *
 * Setup: each test creates a small order ($20) with a known product so
 * the refund / RMA math has a deterministic baseline.
 */
final class ReturnsTest extends IntegrationTestCase
{
    private function makeOrder(float $unitPrice = 20.00): array
    {
        $productId = (int)$this->client->products()->create([
            'name' => $this->uid('RetProd'), 'sku' => $this->uid('ret-prod'),
            'price' => $unitPrice, 'visible' => 1, 'inventory' => 100, 'inventory_track' => 1,
        ])->getData()['productid'];

        $order = $this->client->orders()->create([
            'items' => [[
                'product_id' => $productId, 'quantity' => 1, 'unit_price' => $unitPrice,
                'price_includes_vat' => true, 'vat_ratio' => 24.0,
            ]],
            'shipping_address' => [
                'firstname'=>'R','lastname'=>'T','address1'=>'1 Ret','city'=>'Athens',
                'state'=>'Attica','country'=>'Greece','country_id'=>1,'zip'=>'11111','phone'=>'2100000000',
            ],
            'customer' => ['firstname'=>'R','lastname'=>'T','email'=>'sdkit-ret@example.test'],
            'payment_method' => 'checkout_cashondelivery',
        ])->getData();
        return ['order_id' => (int)$order['order_id'], 'product_id' => $productId, 'unit_price' => $unitPrice];
    }

    #[Test]
    public function create_return_round_trips(): void
    {
        $this->requireScope('orders.write');
        $this->requireScope('orders.read');
        $o = $this->makeOrder();

        $r = $this->client->orders()->createReturn([
            'order_id'   => $o['order_id'],
            'product_id' => $o['product_id'],
            'quantity'   => 1,
            'reason'     => 'damaged in transit',
            'comment'    => 'box dented, item scratched',
            'product_cost' => $o['unit_price'],
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertSame($o['order_id'], (int)$row['retorderid']);
        $this->assertSame(0, (int)$row['retstatus']);
        $this->assertSame('damaged in transit', $row['retreason']);
    }

    #[Test]
    public function create_return_for_unknown_order_returns_400(): void
    {
        $this->requireScope('orders.write');
        try {
            $this->client->orders()->createReturn([
                'order_id'   => 99999999,
                'product_id' => 1,
            ]);
            $this->fail('Expected 400 for unknown order_id');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    #[Test]
    public function transitioning_to_closed_status_bumps_order_refunded_amount(): void
    {
        $this->requireScope('orders.write');
        $this->requireScope('orders.read');
        $o = $this->makeOrder(20.00);

        $ret = $this->client->orders()->createReturn([
            'order_id'   => $o['order_id'],
            'product_id' => $o['product_id'],
            'quantity'   => 1,
            'product_cost' => 20.00,
        ])->getData();
        $rid = (int)$ret['returnid'];

        // Before close: ordrefundedamount must be 0.
        $orderToken = $this->getOrderToken($o['order_id']);
        $before = $this->client->orders()->get($orderToken)->getData();
        $this->assertEqualsWithDelta(0.0, (float)$before['ordrefundedamount'], 0.01);

        // Transition status → 5 (closed/refunded). Server adds
        // product_cost * quantity to ordrefundedamount.
        $this->client->orders()->updateReturn($rid, ['status' => 5]);
        $after = $this->client->orders()->get($orderToken)->getData();
        $this->assertEqualsWithDelta(20.0, (float)$after['ordrefundedamount'], 0.01);
    }

    #[Test]
    public function refund_increments_balance_and_reports_remaining(): void
    {
        $this->requireScope('orders.write');
        $this->requireScope('orders.read');
        $o = $this->makeOrder(50.00);

        $r = $this->client->orders()->refund($o['order_id'], 20.00, 'partial refund')->getData();
        $this->assertEqualsWithDelta(20.0, (float)$r['refunded'], 0.01);
        $this->assertEqualsWithDelta(20.0, (float)$r['total_refunded'], 0.01);
        $this->assertEqualsWithDelta(30.0, (float)$r['remaining'], 0.01);

        // Second refund tops up.
        $r2 = $this->client->orders()->refund($o['order_id'], 10.00)->getData();
        $this->assertEqualsWithDelta(30.0, (float)$r2['total_refunded'], 0.01);
        $this->assertEqualsWithDelta(20.0, (float)$r2['remaining'], 0.01);
    }

    #[Test]
    public function over_refunding_returns_409(): void
    {
        $this->requireScope('orders.write');
        $o = $this->makeOrder(25.00);
        try {
            $this->client->orders()->refund($o['order_id'], 1000.00);
            $this->fail('Expected 409 when refund exceeds remaining balance');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('refundable', $e->getErrorDetail());
        }
    }

    #[Test]
    public function list_returns_filters_by_order_id(): void
    {
        $this->requireScope('orders.read');
        $this->requireScope('orders.write');
        $o = $this->makeOrder();
        $this->client->orders()->createReturn([
            'order_id' => $o['order_id'], 'product_id' => $o['product_id'],
            'reason' => 'taste'
        ]);
        $this->client->orders()->createReturn([
            'order_id' => $o['order_id'], 'product_id' => $o['product_id'],
            'reason' => 'wrong size'
        ]);

        $r = $this->client->orders()->listReturns(['order_id' => $o['order_id']])->getData();
        $this->assertGreaterThanOrEqual(2, count($r));
        foreach ($r as $row) {
            $this->assertSame($o['order_id'], (int)$row['retorderid']);
        }
    }

    /**
     * Order token lookup via the orders list — get(token) is what
     * verifies ordrefundedamount above. We need the token here because
     * the orders.get endpoint takes a 32-char token, not the integer id.
     */
    private function getOrderToken(int $orderId): string
    {
        $rows = $this->client->orders()->list(['limit' => 200])->getData();
        foreach ($rows as $r) {
            if ((int)$r['orderid'] === $orderId) {
                return (string)$r['ordtoken'];
            }
        }
        $this->fail('order ' . $orderId . ' not visible in /orders/list — cannot verify refund state');
    }
}
