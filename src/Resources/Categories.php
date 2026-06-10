<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Categories resource — /api/categories.
 *
 * The category tree uses nested-set storage on the server. Creating a new
 * category triggers a tree rebuild automatically (RebuildCategoryTreeByParent).
 *
 * Reads: categories.read. Writes: categories.write.
 */
class Categories
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Full category tree ordered by nested-set left position. Each row
     * carries a populated `url` and (if present) a fully-qualified `image` URL.
     */
    public function list(): Response
    {
        return $this->http->get('/categories/list');
    }

    /**
     * Search categories by `catname` substring. Params: search, start, limit.
     */
    public function search(array $params = []): Response
    {
        return $this->http->get('/categories/search', $params);
    }

    public function get(int $categoryId): Response
    {
        return $this->http->get('/categories/category/' . $categoryId);
    }

    /**
     * Create a category. Required: catname. Optional: catcurl (slug, auto-
     * generated from catname when omitted), catdesc, catparentid (default 0),
     * catvisible (default 1), catsort, catpagetitle, catmetadesc,
     * catmetakeywords, catimageid, catfeatured.
     */
    public function create(array $data): Response
    {
        return $this->http->post('/categories/create', $data);
    }

    public function update(int $categoryId, array $data): Response
    {
        return $this->http->put('/categories/category/' . $categoryId, $data);
    }

    /**
     * Create up to 500 categories in one call, including sub-categories.
     * Scope: categories.write.
     *
     * Sub-categories reference their parent in one of two ways:
     *  - `parent_ref` (string) — points to another row's `ref` in the same
     *    batch. The server resolves the dependency in topological order so
     *    parents land first.
     *  - `catparentid` (int) — an existing category id. Wins over
     *    `parent_ref` when both are present.
     *
     * `ref` is the caller-assigned local identifier. Must be unique within
     * the batch; only needed on rows that other rows reference.
     *
     * Failure model:
     *  - Validation errors (missing key, > 500 rows, duplicate ref, cycle,
     *    dangling parent_ref) → ApiException (server returns 400).
     *  - Per-row INSERT failures → reported in `results[].success=false`;
     *    children that depended on a failed parent are reported too.
     *
     * @param array $rows Up to 500 category payloads. Same fields as
     *                    create() plus optional `ref` and `parent_ref`.
     * @return Response   data: { results, created, failed, total }
     *                    results: [{ index, ref?, success, category_id|error }]
     */
    public function batchCreate(array $rows): Response
    {
        return $this->http->post('/categories/batch/create', ['categories' => $rows]);
    }
}
