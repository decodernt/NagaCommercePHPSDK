<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * News resource — covers /api/articles and /api/news-categories. Scope: news.read.
 *
 * News in NagaCommerce is the storefront's content stream — articles are
 * grouped under categories. The server exposes article reads (list, get,
 * search) + writes (create, update, delete), and category reads (list,
 * get, search) + create.
 */
class News
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List articles, optionally scoped to a category (by id or curl slug).
     */
    public function listArticles(?string $categoryIdOrSlug = null): Response
    {
        $path = '/articles/list';
        if ($categoryIdOrSlug !== null && $categoryIdOrSlug !== '') {
            $path .= '/' . rawurlencode($categoryIdOrSlug);
        }
        return $this->http->get($path);
    }

    /**
     * Get an article by id or curl slug. Server-side this triggers an
     * `api.news.article.viewed` event for analytics.
     */
    public function getArticle(string $idOrSlug): Response
    {
        return $this->http->get('/articles/article/' . rawurlencode($idOrSlug));
    }

    /**
     * Full-text search across the article catalog. The path parameter IS
     * the search term — not a query string.
     */
    public function searchArticles(string $term): Response
    {
        return $this->http->get('/articles/find/' . rawurlencode($term));
    }

    public function listCategories(): Response
    {
        return $this->http->get('/news-categories/list');
    }

    public function getCategory(string $idOrSlug): Response
    {
        return $this->http->get('/news-categories/category/' . rawurlencode($idOrSlug));
    }

    /**
     * Search categories. Params: search, start, limit.
     */
    public function searchCategories(array $params = []): Response
    {
        return $this->http->get('/news-categories/find', $params);
    }

    /**
     * Create a news category. Scope: news.write
     *
     * Required: `newscattitle` (string, max 250 chars).
     * Optional: `newscatdescription`, `newscatcurl` (slug — auto-derived
     *   from title when omitted), `newscatvisible` (0/1), `newscatlanguage`,
     *   `newscatlayout`, `newscatsidebarid`.
     *
     * Friendly aliases also accepted: `title` → `newscattitle`,
     * `description` → `newscatdescription`.
     *
     * Defaults applied on create only:
     *   - `newscatvisible: 0` (hidden until you publish)
     *   - `newscatlanguage: el`, `newscatlayout: newscategory.html`
     *
     * The response carries the inserted category row. Use its
     * `newscategoryid` as a `categories` entry when creating articles.
     */
    public function createCategory(array $data): Response
    {
        return $this->http->post('/news-categories/create', $data);
    }

    /**
     * Create a news article. Scope: news.write
     *
     * Required: `newstitle` (string, max 250 chars).
     * Optional: `newscontent`, `newsshortdesc`, `newssearchkeywords`,
     *   `newsdate` (unix int or ISO 8601 string), `newsvisible` (0/1),
     *   `newscurl` (slug — auto-derived from title when omitted),
     *   `newscategory`, `newstags`, `newslanguage`, `newscontenttype`,
     *   `disableComments`.
     *
     * Friendly aliases also accepted:
     *   - `categories` (int[]) — joined into `newscategory` as CSV
     *   - `tags` (string[])    — joined into `newstags` as CSV
     *   - `images` (array)     — same shape as Products::IMAGES_SHAPE;
     *                            downloaded server-side via MEDIAMANAGER,
     *                            attached to news_gallery
     *
     * The response carries the inserted article row plus an
     * `images_result` envelope (when `images` was sent) with per-URL
     * outcomes. URL-download failures stay non-fatal — the article is
     * created either way.
     *
     * Defaults applied on create only:
     *   - `newsvisible: 0` (admin must explicitly publish)
     *   - `newsdate`: now()
     *   - `newscontenttype: text`, `newslanguage: el`
     */
    public function createArticle(array $data): Response
    {
        return $this->http->post('/articles/create', $data);
    }

    /**
     * Update an article. Scope: news.write
     *
     * Partial update — only fields you send are written. Sending `images`
     * REPLACES the article's gallery (matches admin behavior); omit to
     * leave existing images alone. Send `images: []` to clear.
     */
    public function updateArticle(int $articleId, array $data): Response
    {
        return $this->http->put('/articles/article/' . $articleId, $data);
    }

    /**
     * Delete an article + its gallery, search index, words, and comments.
     * Scope: news.delete
     *
     * news_search / news_words / news_comments cascade via FK; news_gallery
     * doesn't have an FK to news so the server explicitly deletes those
     * rows in the same transaction.
     */
    public function deleteArticle(int $articleId): Response
    {
        return $this->http->delete('/articles/article/' . $articleId);
    }
}
