<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Pricelists resource — /api/pricelists. Scope: pricelists.read.
 *
 * The server scopes results to the API key — only pricelists explicitly
 * assigned to the calling key (via api_key_price_lists) are returned. This
 * lets the source store control which price data each partner sees.
 *
 * Items endpoint paginates via start + limit (default 100, max 500).
 */
class Pricelists
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function list(): Response
    {
        return $this->http->get('/pricelists/list');
    }

    public function items(int $priceListId, array $params = []): Response
    {
        return $this->http->get('/pricelists/pricelist/' . $priceListId . '/items', $params);
    }

    // ---- CRUD (scope: pricelists.write / pricelists.delete) ----

    /**
     * Create a price list. Required: `name`. Optional:
     *   - description, currency (ISO 4217), status (bool, default true),
     *     is_default (bool — server demotes prior default),
     *     prices_entered_with_tax (bool), discard_discounts (bool),
     *     priority (int, sort).
     */
    public function create(array $data): Response
    {
        return $this->http->post('/pricelists/', $data);
    }

    public function update(int $priceListId, array $data): Response
    {
        return $this->http->put('/pricelists/pricelist/' . $priceListId, $data);
    }

    /**
     * Delete a price list. FK cascade removes its items; the customer-group
     * link rows are removed explicitly in the same transaction.
     */
    public function delete(int $priceListId): Response
    {
        return $this->http->delete('/pricelists/pricelist/' . $priceListId);
    }

    /**
     * Upsert a single price-list item (per-product price override).
     * Required: `product_id`. Optional: `combination_id` (for variant
     * overrides), `price`, `sale_price`, `sale_price_from` /
     * `sale_price_to` (ISO 8601 string or unix int).
     *
     * Uniqueness key: (price_list_id, product_id, combination_id). Re-
     * posting for the same triple updates the existing row.
     */
    public function upsertItem(int $priceListId, array $data): Response
    {
        return $this->http->post('/pricelists/pricelist/' . $priceListId . '/items', $data);
    }

    public function updateItem(int $priceListId, int $itemId, array $data): Response
    {
        return $this->http->put('/pricelists/pricelist/' . $priceListId . '/items/' . $itemId, $data);
    }

    public function deleteItem(int $priceListId, int $itemId): Response
    {
        return $this->http->delete('/pricelists/pricelist/' . $priceListId . '/items/' . $itemId);
    }

    /**
     * Bulk upsert price-list items (up to 5000 per call). Use for
     * wholesale catalog sync; per-row failures are reported in the
     * `results[]` envelope without failing the whole call.
     */
    public function bulkUpsertItems(int $priceListId, array $items): Response
    {
        return $this->http->post('/pricelists/pricelist/' . $priceListId . '/items/bulk', ['items' => $items]);
    }
}
