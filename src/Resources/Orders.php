<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

class Orders
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List orders with optional filtering and pagination. Scope: orders.read
     *
     * @param array $params Supported keys:
     *   - start (int) offset, default 0
     *   - limit (int) max 200, default 50
     *   - status (int) filter by order status
     */
    public function list(array $params = []): Response
    {
        return $this->http->get('/orders/list', $params);
    }

    /**
     * Total order count. Scope: orders.read
     */
    public function count(): Response
    {
        return $this->http->get('/orders/count');
    }

    /**
     * Orders modified since a UNIX timestamp. Scope: orders.read
     */
    public function updatedSince(int $sinceTimestamp, array $params = []): Response
    {
        return $this->http->get('/orders/updated-since/' . $sinceTimestamp, $params);
    }

    /**
     * Get a single order by its 32-char hex token (includes products + addresses).
     * Scope: orders.read
     */
    public function get(string $orderToken): Response
    {
        return $this->http->get('/orders/order/' . $orderToken);
    }

    /**
     * Create a new order.
     *
     * The pricing mode is determined server-side based on the API key's scopes:
     *   - Keys with `orders.custom_pricing` scope: custom mode (unit_price from payload)
     *   - Keys without that scope: catalog mode (prices calculated from product catalog + customer group)
     *
     * @param array $data Order data. Structure:
     *   - items (array, required) each item: product_id, quantity, variation_id
     *       In custom pricing mode also: unit_price, price_includes_vat, vat_ratio
     *   - shipping_address (array, required) keys: firstname, lastname, address1, city, state, country, zip, phone
     *   - billing_address (array, optional) same keys as shipping_address
     *   - customer (array) keys: id, firstname, lastname, email, group_id
     *   - shipping (array) keys: cost, description, module, is_custom
     *   - invoice_details (array) keys: company, vat_number, doy, profession, address
     *   - payment_method (string) payment module identifier
     *   - payment_display_name (string)
     *   - customer_message (string)
     *   - channel_id (int)
     *   - channel_order_id (string)
     *   - currency_id (int)
     *
     * @return Response Contains order_id, order_token, and pricing_mode in data.
     *   Store the order_token -- it is required to cancel the order later.
     */
    public function create(array $data): Response
    {
        return $this->http->post('/orders/create', $data);
    }

    /**
     * Cancel an order. Requires the order_token received from create().
     *
     * Cancellation is rejected for orders that are already Shipped,
     * Partially Shipped, Refunded, Cancelled, or Returned.
     *
     * @param int    $id         Order ID
     * @param string $orderToken The order_token returned when the order was created
     */
    public function cancel(int $id, string $orderToken): Response
    {
        return $this->http->post('/orders/order/' . $id . '/cancel', [
            'order_token' => $orderToken,
        ]);
    }

    /**
     * Update an order's status. Scope: orders.write
     *
     * @param int    $id      Order ID
     * @param int    $status  New status code
     * @param string $comment Optional comment for the status change history
     */
    public function updateStatus(int $id, int $status, string $comment = ''): Response
    {
        $data = ['status' => $status];
        if ($comment !== '') {
            $data['comment'] = $comment;
        }
        return $this->http->put('/orders/order/' . $id . '/status', $data);
    }

    // -----------------------------------------------------------------
    // Line-item editing (post-create)
    // -----------------------------------------------------------------

    /** List the rows in this order's `order_products` table. */
    public function listItems(int $orderId): Response
    {
        return $this->http->get('/orders/order/' . $orderId . '/items');
    }

    /**
     * Append a line to an existing order. Required: `product_id`,
     * `quantity`. Optional: `unit_price` (defaults to catalog),
     * `unit_price_includes_vat` (bool, default true), `vat_ratio`
     * (% as float, default 0 = no tax breakdown), `name` (override
     * the catalog name on this line).
     *
     * Side effect: server recomputes the order's subtotals / total /
     * `ordtotalqty` after the insert.
     */
    public function addItem(int $orderId, array $data): Response
    {
        return $this->http->post('/orders/order/' . $orderId . '/items', $data);
    }

    /**
     * Patch a line. Common keys: `quantity`, `unit_price`,
     * `unit_price_includes_vat`, `vat_ratio`, `name`. Order totals
     * recomputed after the update.
     */
    public function updateItem(int $orderId, int $itemId, array $data): Response
    {
        return $this->http->put('/orders/order/' . $orderId . '/items/' . $itemId, $data);
    }

    /**
     * Remove a line. Order totals recomputed after the delete; an order
     * may legitimately end up at total_inc_tax = 0 if every line was
     * removed (call cancel() at that point to finalize).
     */
    public function deleteItem(int $orderId, int $itemId): Response
    {
        return $this->http->delete('/orders/order/' . $orderId . '/items/' . $itemId);
    }

    // -----------------------------------------------------------------
    // Refunds + Returns (RMA)
    //
    // The returns surface lives under /returns; refund-an-order without
    // an RMA row lives under /returns/refund/{id}. Both share the
    // orders.* scope family. We expose them under orders()->returns()
    // / orders()->refund() for partner ergonomics.
    // -----------------------------------------------------------------

    /**
     * List returns. Optional filters:
     *   - order_id (int), customer_id (int), status (int 0-5)
     *   - start, limit
     */
    public function listReturns(array $params = []): Response
    {
        return $this->http->get('/returns/', $params);
    }

    public function getReturn(int $returnId): Response
    {
        return $this->http->get('/returns/' . $returnId);
    }

    /**
     * Create a return / RMA row. Required: `order_id`, `product_id`.
     * Common optional: `quantity` (default 1), `reason`, `action`,
     * `comment`, `staff_notes`, `customer_id` (defaults to the order's
     * customer), `variation_id`, `product_cost`, `status` (0 = pending),
     * `inventory_returned` (bool — flips when the warehouse receives it).
     *
     * Status semantics:
     *   0=pending 1=under_review 2=approved 3=rejected 4=received 5=closed
     *
     * Transitioning `status` to 5 (closed) adds `product_cost * quantity`
     * to the parent order's `ordrefundedamount` automatically.
     */
    public function createReturn(array $data): Response
    {
        return $this->http->post('/returns/', $data);
    }

    public function updateReturn(int $returnId, array $data): Response
    {
        return $this->http->put('/returns/' . $returnId, $data);
    }

    public function deleteReturn(int $returnId): Response
    {
        return $this->http->delete('/returns/' . $returnId);
    }

    /**
     * Apply a refund to an order directly (without going through the
     * RMA flow). Useful for payment-gateway integrations that surface
     * refund events. Refuses (409) when the requested amount would push
     * `ordrefundedamount` above `total_inc_tax`.
     *
     * Body: { amount: float, reason?: string }.
     */
    public function refund(int $orderId, float $amount, string $reason = ''): Response
    {
        $body = ['amount' => $amount];
        if ($reason !== '') { $body['reason'] = $reason; }
        return $this->http->post('/returns/refund/' . $orderId, $body);
    }
}
