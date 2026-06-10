<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Coupons resource — discount-code CRUD. Scope: `coupons.read` / `coupons.write`
 * / `coupons.delete`.
 *
 * Coupon types (sent as `type` int OR `type_name` string):
 *
 *   1 / percent_item       Percent off applicable items
 *   2 / amount_item        Fixed amount off applicable items
 *   3 / free_shipping      Free shipping on applicable items
 *   4 / amount_subtotal    Fixed amount off cart subtotal
 *   5 / percent_subtotal   Percent off cart subtotal
 *
 * The `code` column is uniquely indexed — duplicate codes return 409.
 *
 * Most fields are optional with sensible defaults (enabled=1,
 * applies_to=products, type=1). The bare minimum for a functioning
 * coupon is { name, code, amount, type }.
 */
class Coupons
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List coupons. Optional query params:
     *   - enabled (int 0/1) — filter by status
     *   - type (int)        — filter by coupontype
     *   - start, limit
     */
    public function list(array $params = []): Response
    {
        return $this->http->get('/coupons/', $params);
    }

    public function get(int $couponId): Response
    {
        return $this->http->get('/coupons/' . $couponId);
    }

    /**
     * Look up a coupon by its code (the partner-facing identifier).
     * 404 when no coupon carries that code.
     */
    public function getByCode(string $code): Response
    {
        return $this->http->get('/coupons/by-code/' . rawurlencode($code));
    }

    /**
     * Create a coupon. Required: `name`, `code`. Common optional:
     *   - type (1-5) OR type_name (see class docblock)
     *   - amount (float)
     *   - min_purchase (float)
     *   - expires (ISO 8601 string OR unix int)
     *   - enabled (bool, default true)
     *   - applies_to ('products' | 'categories')
     *   - max_uses, max_uses_per_customer (ints)
     *   - limit_by_discount, limit_by_customers_only (bool)
     *   - restrict_by_category (CSV string of category ids)
     *   - restrict_by_category_inc_subs (bool)
     *   - exclude_customer_group_ids (int[] or CSV)
     *   - assigned_email (string — restricts coupon to one customer)
     *
     * Returns 409 if the code is already in use.
     */
    public function create(array $data): Response
    {
        return $this->http->post('/coupons/', $data);
    }

    public function update(int $couponId, array $data): Response
    {
        return $this->http->put('/coupons/' . $couponId, $data);
    }

    /**
     * Delete a coupon. Cascade: order_coupons rows pointing at this
     * coupon (historical redemptions) are removed via FK ON DELETE
     * CASCADE; coupon_locations / shipping / payment restriction rows
     * are removed explicitly.
     */
    public function delete(int $couponId): Response
    {
        return $this->http->delete('/coupons/' . $couponId);
    }
}
