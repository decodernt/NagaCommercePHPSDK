<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

class Products
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List all products.
     */
    public function list(): Response
    {
        return $this->http->get('/products/list');
    }

    /**
     * Get total product count.
     */
    public function count(): Response
    {
        return $this->http->get('/products/count');
    }

    /**
     * Get a single product by ID.
     */
    public function get(int $id): Response
    {
        return $this->http->get('/products/' . $id);
    }

    /**
     * Search products.
     *
     * @param array $params Supported keys:
     *   - search_query (string)
     *   - category_ids (array|string CSV)
     *   - brand_ids (array|string CSV)
     *   - sort (string: name, name_desc, date, date_desc, id, id_desc, newest, priceasc, pricedesc, bestselling)
     *   - start (int)
     *   - limit (int)
     */
    public function search(array $params = []): Response
    {
        return $this->http->get('/products/find', $params);
    }

    /**
     * Create a new product.
     *
     * @param array $data Product fields. Accepted friendly names:
     *   name, sku, description, short_description, price, cost_price, sale_price,
     *   weight, width, height, depth, visible, featured, inventory, low_inventory,
     *   inventory_track, brand_id, availability_id, condition, search_keywords,
     *   sort_order, page_title, meta_description, warranty, allow_purchases,
     *   free_shipping, fixed_shipping, min_qty, max_qty, upc, ean, mpn, gtin,
     *   isbn, product_curl, categories (array of category IDs)
     */
    public function create(array $data): Response
    {
        return $this->http->post('/products/create', $data);
    }

    /**
     * Update an existing product.
     *
     * @param int   $id   Product ID
     * @param array $data Fields to update (same keys as create)
     */
    public function update(int $id, array $data): Response
    {
        return $this->http->put('/products/' . $id, $data);
    }
}
