<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Brands resource — /api/brands.
 *
 * Reads: brands.read. Writes: brands.write.
 * Create is idempotent on `brandname` — passing an already-existing name
 * returns the existing row with `meta.existing = true` (HTTP 200, NOT 201).
 *
 * Update accepts `brandimagefile` as either a stored relative path or an
 * `https://` URL. URLs are downloaded server-side via MEDIAMANAGER and the
 * resulting `mediaid` is stored on the brand.
 */
class Brands
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function list(): Response
    {
        return $this->http->get('/brands/list');
    }

    /**
     * Search brands. Supported query params: search, start, limit.
     */
    public function search(array $params = []): Response
    {
        return $this->http->get('/brands/search', $params);
    }

    /**
     * Create or find a brand. Fields: brandname (required), brandslug,
     * brandimagefile, brandproductsortorder.
     */
    public function create(array $data): Response
    {
        return $this->http->post('/brands/create', $data);
    }

    /**
     * Update mutable fields. Same allowlist as create + `brandimageid`.
     */
    public function update(int $brandId, array $data): Response
    {
        return $this->http->put('/brands/brand/' . $brandId, $data);
    }
}
