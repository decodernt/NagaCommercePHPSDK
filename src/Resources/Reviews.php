<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Reviews resource — product + vendor review CRUD.
 *
 * Status semantics on `status` (revstatus column):
 *   0 — pending moderation (default on create)
 *   1 — approved / visible on the storefront, COUNTS toward avg rating
 *   2 — rejected / hidden
 *
 * Every write recomputes the affected product's `prodratingtotal` +
 * `prodnumratings` server-side from `SUM(rating)` / `COUNT(*)` over
 * status=1 rows of type=product. Bulk-moderation workflows can flip
 * many rows without drift.
 *
 * Scopes: `reviews.read` / `reviews.write` / `reviews.delete`.
 * Backwards-compat: `products.read` / `products.write` / `products.delete`
 * are accepted for the lifetime of v1 so existing keys keep working.
 */
class Reviews
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List reviews. Optional query params:
     *   - product_id (int) — restrict to one product
     *   - status (int)     — 0/1/2 filter
     *   - type (string)    — 'product' (default) | 'vendor'
     *   - start, limit (ints, limit capped at 500)
     */
    public function list(array $params = []): Response
    {
        return $this->http->get('/reviews/', $params);
    }

    public function get(int $reviewId): Response
    {
        return $this->http->get('/reviews/' . $reviewId);
    }

    /**
     * Create a review. Required: `product_id`, `rating` (0-5).
     * Optional: `title`, `text`, `from_name`, `order_id`, `user_id`,
     * `type` ('product'|'vendor'), `status` (defaults to 0 = pending),
     * `date` (unix int or ISO 8601 string; defaults to now).
     */
    public function create(array $data): Response
    {
        return $this->http->post('/reviews/', $data);
    }

    /**
     * Update a review. Partial. Cannot move a review to a different
     * product (would corrupt the rating aggregate on both products).
     */
    public function update(int $reviewId, array $data): Response
    {
        return $this->http->put('/reviews/' . $reviewId, $data);
    }

    /**
     * Delete a review. The product's rating aggregate is recomputed
     * in the same call.
     */
    public function delete(int $reviewId): Response
    {
        return $this->http->delete('/reviews/' . $reviewId);
    }
}
