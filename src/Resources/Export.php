<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Export resource — /api/export/products. Scope: products.export.
 *
 * Heavy paginated dump intended for bulk ingestion (catalog → ERP, feed
 * generators, analytics warehouses). Pagination uses `page` + `per_page`
 * (NOT start/limit like the other read endpoints — yes it's inconsistent).
 *
 * Filtering is via a JSON body with a filter DSL — pass each filter as
 * { field, type, value }. Supported fields and operators:
 *
 *   prodname              is / contains / starts_with / ends_with
 *   prodprice             is / is_more / is_less / is_more_or_equal /
 *                         is_less_or_equal / is_in / is_not_in
 *   prodsaleprice         (same set as prodprice)
 *   categories            is / is_not / is_in / is_not_in
 *   brand, prodbrandid    is / is_not / is_in / is_not_in
 *   productid             is / is_not / is_in / is_not_in
 *   exclude_products      is_not / is_not_in
 *   exclude_brands        is_not / is_not_in
 *   prodvisible           is / is_not
 *   pricelist_id          is_in
 *
 * Each product row includes custom_fields, options, prices, and tags.
 */
class Export
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * @param int   $page
     * @param int   $perPage
     * @param array $filters       optional array of { field, type, value } entries
     * @param array $priceListIds  optional pricelist scoping
     */
    public function products(int $page = 1, int $perPage = 100, array $filters = [], array $priceListIds = []): Response
    {
        // The server route matches both GET and POST; we always POST so the
        // filter body can ride along on the same call. Page / per_page stay
        // on the query string because the server reads them from $_GET.
        $path = '/export/products/?' . http_build_query([
            'page'     => $page,
            'per_page' => $perPage,
            'format'   => 'json',
        ]);

        $body = [];
        if (!empty($filters)) {
            $body['filters'] = ['filter' => $filters];
        }
        if (!empty($priceListIds)) {
            $body['price_list_ids'] = $priceListIds;
        }

        return $this->http->post($path, $body);
    }
}
