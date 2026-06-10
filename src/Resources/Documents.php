<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Documents resource — render printable order documents on demand.
 *
 * Scope: `orders.read`.
 *
 * Three document types: 'invoice', 'packing_slip', 'shipment_slip'.
 * The JSON endpoints return `{ order_id, doc_type, generated_at,
 * html, html_bytes }`; the `.html` endpoints return raw HTML directly
 * (use them for partner UIs that embed the document in an iframe or
 * push it through a PDF renderer).
 *
 * AADE-compliant document records (with MARK / UID / QR / digital
 * signature) are a separate roadmap item — this surface is the
 * printable-document MVP. See server-side controller doc for details.
 */
class Documents
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Render the invoice for an order. Returns the HTML body inside
     * the JSON envelope.
     */
    public function invoice(int $orderId): Response
    {
        return $this->http->get('/documents/order/' . $orderId . '/invoice');
    }

    /**
     * Render the order's packing slip. Same envelope as invoice().
     */
    public function packingSlip(int $orderId): Response
    {
        return $this->http->get('/documents/order/' . $orderId . '/packing_slip');
    }

    /**
     * Render a shipment-specific packing slip. Same envelope.
     */
    public function shipmentSlip(int $orderId): Response
    {
        return $this->http->get('/documents/order/' . $orderId . '/shipment_slip');
    }
}
