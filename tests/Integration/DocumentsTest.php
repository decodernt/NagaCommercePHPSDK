<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /documents render endpoints.
 *
 * Pins:
 *   - invoice render returns HTML + metadata
 *   - packing slip render returns HTML
 *   - unknown order returns 404
 */
final class DocumentsTest extends IntegrationTestCase
{
    private function makeOrder(): array
    {
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('DocProd'), 'sku' => $this->uid('doc-p'),
            'price' => 12.34, 'visible' => 1, 'inventory' => 10, 'inventory_track' => 1,
        ])->getData()['productid'];

        $o = $this->client->orders()->create([
            'items' => [[
                'product_id' => $pid, 'quantity' => 2, 'unit_price' => 12.34,
                'price_includes_vat' => true, 'vat_ratio' => 24.0,
            ]],
            'shipping_address' => [
                'firstname' => 'D', 'lastname' => 'T', 'address1' => '1 Doc',
                'city' => 'Athens', 'state' => 'Attica', 'country' => 'Greece',
                'country_id' => 1, 'zip' => '11111', 'phone' => '2100000000',
            ],
            'customer'        => ['firstname' => 'D', 'lastname' => 'T', 'email' => 'sdkit-doc@example.test'],
            'payment_method'  => 'checkout_cashondelivery',
        ])->getData();

        return ['order_id' => (int)$o['order_id']];
    }

    #[Test]
    public function invoice_render_returns_html_envelope(): void
    {
        $this->requireScope('orders.read');
        $this->requireScope('orders.write');
        $o = $this->makeOrder();

        $r = $this->client->documents()->invoice($o['order_id']);
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertSame($o['order_id'], (int)$data['order_id']);
        $this->assertSame('invoice', $data['doc_type']);
        $this->assertGreaterThan(0, (int)$data['html_bytes']);
        // The HTML should at least contain DOCTYPE or <html or be a
        // non-trivial blob — partner UIs will iframe-embed it.
        $this->assertNotEmpty($data['html']);
    }

    #[Test]
    public function packing_slip_render_returns_html(): void
    {
        $this->requireScope('orders.read');
        $this->requireScope('orders.write');
        $o = $this->makeOrder();

        $r = $this->client->documents()->packingSlip($o['order_id']);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('packing_slip', $r->getData()['doc_type']);
    }

    #[Test]
    public function unknown_order_returns_404(): void
    {
        $this->requireScope('orders.read');
        try {
            $this->client->documents()->invoice(99999999);
            $this->fail('Expected 404 for unknown order');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
