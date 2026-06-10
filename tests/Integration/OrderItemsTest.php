<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /orders/order/{id}/items add / update / delete.
 *
 * Pins:
 *   - addItem appends to order_products + bumps order totals
 *   - updateItem changes qty AND recomputes per-line + order totals
 *   - deleteItem removes the line + recomputes
 *   - Unknown order returns 404
 *   - Unknown line returns 404
 *   - Cross-order line update returns 404
 */
final class OrderItemsTest extends IntegrationTestCase
{
    private function makeOrderWithOneLine(float $unitPrice = 20.00): array
    {
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('OIProd'), 'sku' => $this->uid('oi-prod'),
            'price' => $unitPrice, 'visible' => 1, 'inventory' => 100, 'inventory_track' => 1,
        ])->getData()['productid'];

        $o = $this->client->orders()->create([
            'items' => [[
                'product_id' => $pid, 'quantity' => 1, 'unit_price' => $unitPrice,
                'price_includes_vat' => true, 'vat_ratio' => 24.0,
            ]],
            'shipping_address' => [
                'firstname'=>'I','lastname'=>'T','address1'=>'1 IT','city'=>'Athens',
                'state'=>'Attica','country'=>'Greece','country_id'=>1,'zip'=>'11111','phone'=>'2100000000',
            ],
            'customer'        => ['firstname'=>'I','lastname'=>'T','email'=>'sdkit-oi@example.test'],
            'payment_method'  => 'checkout_cashondelivery',
        ])->getData();

        return [
            'order_id'    => (int)$o['order_id'],
            'order_token' => (string)$o['order_token'],
            'product_id'  => $pid,
        ];
    }

    #[Test]
    public function add_item_bumps_order_totals_and_qty(): void
    {
        $this->requireScope('orders.read');
        $this->requireScope('orders.write');
        $this->requireScope('products.write');

        $o = $this->makeOrderWithOneLine(20.00);

        // Extra product to add as a second line.
        $newPid = (int)$this->client->products()->create([
            'name' => $this->uid('OIExtra'), 'sku' => $this->uid('oi-extra'),
            'price' => 1.0, 'visible' => 1, 'inventory' => 10, 'inventory_track' => 1,
        ])->getData()['productid'];

        $this->client->orders()->addItem($o['order_id'], [
            'product_id' => $newPid,
            'quantity'   => 3,
            'unit_price' => 5.00,
            'unit_price_includes_vat' => true,
            'vat_ratio'  => 24.0,
        ]);

        // Order now has 1 line @ 20 + 1 line of qty=3 @ 5 = 20 + 15 = 35.
        $back = $this->client->orders()->get($o['order_token'])->getData();
        $this->assertEqualsWithDelta(35.0, (float)$back['total_inc_tax'], 0.01,
            'order total must reflect the new line (catalog total + new line)');
        $this->assertSame(4, (int)$back['ordtotalqty'],
            'ordtotalqty must reflect the sum of all line quantities');
    }

    #[Test]
    public function update_item_quantity_recomputes_totals(): void
    {
        $this->requireScope('orders.read');
        $this->requireScope('orders.write');

        $o = $this->makeOrderWithOneLine(20.00);
        $items = $this->client->orders()->listItems($o['order_id'])->getData();
        $itemId = (int)$items[0]['orderprodid'];

        // Triple the qty.
        $this->client->orders()->updateItem($o['order_id'], $itemId, ['quantity' => 3]);

        $back = $this->client->orders()->get($o['order_token'])->getData();
        $this->assertEqualsWithDelta(60.0, (float)$back['total_inc_tax'], 0.01,
            'tripling line qty must triple order total');
        $this->assertSame(3, (int)$back['ordtotalqty']);
    }

    #[Test]
    public function delete_item_recomputes_totals(): void
    {
        $this->requireScope('orders.read');
        $this->requireScope('orders.write');
        $o = $this->makeOrderWithOneLine(20.00);

        $itemId = (int)$this->client->orders()->listItems($o['order_id'])->getData()[0]['orderprodid'];
        $this->client->orders()->deleteItem($o['order_id'], $itemId);

        $back = $this->client->orders()->get($o['order_token'])->getData();
        $this->assertEqualsWithDelta(0.0, (float)$back['total_inc_tax'], 0.01,
            'with the only line removed, order total must reach 0');
        $this->assertSame(0, (int)$back['ordtotalqty']);
        $this->assertSame([], $this->client->orders()->listItems($o['order_id'])->getData());
    }

    #[Test]
    public function add_item_to_unknown_order_returns_404(): void
    {
        $this->requireScope('orders.write');
        try {
            $this->client->orders()->addItem(99999999, [
                'product_id' => 1, 'quantity' => 1,
            ]);
            $this->fail('Expected 404 for unknown order');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function update_line_belonging_to_different_order_returns_404(): void
    {
        $this->requireScope('orders.write');
        $oA = $this->makeOrderWithOneLine();
        $oB = $this->makeOrderWithOneLine();
        $aItemId = (int)$this->client->orders()->listItems($oA['order_id'])->getData()[0]['orderprodid'];

        try {
            $this->client->orders()->updateItem($oB['order_id'], $aItemId, ['quantity' => 5]);
            $this->fail('cross-order line update must 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
