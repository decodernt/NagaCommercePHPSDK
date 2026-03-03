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
     * List orders with optional filtering and pagination.
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
     * Get total order count.
     */
    public function count(): Response
    {
        return $this->http->get('/orders/count');
    }

    /**
     * Get a single order by its token (includes products and addresses).
     *
     * @param string $orderToken The 32-char order token returned from create()
     */
    public function get(string $orderToken): Response
    {
        return $this->http->get('/orders/' . $orderToken);
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
        return $this->http->post('/orders/' . $id . '/cancel', [
            'order_token' => $orderToken,
        ]);
    }

    /**
     * Update an order's status.
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
        return $this->http->put('/orders/' . $id . '/status', $data);
    }
}
