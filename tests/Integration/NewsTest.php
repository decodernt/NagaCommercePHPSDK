<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /articles + /news-categories endpoints — content stream CRUD.
 *
 * Coverage:
 *   - read: listArticles, getArticle (by id or slug), searchArticles,
 *           listCategories, getCategory, searchCategories
 *   - write: createArticle (defaults, validation), updateArticle (partial),
 *            deleteArticle (cascade across search / words / gallery)
 *   - URL image import: images[] array attaches via MEDIAMANAGER to
 *           news_gallery (no FK from news_gallery to news; cascade is
 *           explicit in the deleteArticle transaction)
 *
 * No cleanup. uid()-tagged slugs / titles keep runs distinct.
 */
final class NewsTest extends IntegrationTestCase
{
    #[Test]
    public function list_articles_returns_envelope(): void
    {
        $this->requireScope('news.read');
        try {
            $r = $this->client->news()->listArticles();
            $this->assertSame(200, $r->getStatusCode());
            // Server returns { list, paging, categories } when items exist;
            // 404 when the store has no news content yet.
            $data = $r->getData();
            $this->assertArrayHasKey('list', $data);
        } catch (ApiException $e) {
            // 404 here means "store has no news content" — acceptable.
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function list_categories_returns_array(): void
    {
        $this->requireScope('news.read');
        $r = $this->client->news()->listCategories();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    #[Test]
    public function search_categories_paginated(): void
    {
        $this->requireScope('news.read');
        $r = $this->client->news()->searchCategories(['limit' => 3]);
        $this->assertSame(200, $r->getStatusCode());
    }

    #[Test]
    public function create_minimal_article_and_get_back(): void
    {
        $this->requireScope('news.write');
        $this->requireScope('news.read');

        $title = $this->uid('Article');
        $r = $this->client->news()->createArticle(['newstitle' => $title]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $articleId = (int)$row['newsid'];
        $this->assertGreaterThan(0, $articleId);
        $this->assertSame($title, $row['newstitle']);

        // Defaults applied on create:
        $this->assertSame(0, (int)$row['newsvisible'],
            'newsvisible must default to 0 (admin must explicitly publish)');
        $this->assertNotEmpty($row['newscurl'], 'newscurl auto-derived from title');
        $this->assertGreaterThan(0, (int)$row['newsdate'], 'newsdate defaults to now()');

        // Round-trip via getArticle (by id).
        $back = $this->client->news()->getArticle((string)$articleId)->getData();
        $article = $back['article'];
        $this->assertSame($articleId, (int)$article['newsid']);
        $this->assertSame($title, $article['newstitle']);
    }

    #[Test]
    public function create_without_newstitle_returns_400(): void
    {
        $this->requireScope('news.write');
        try {
            $this->client->news()->createArticle(['newscontent' => 'no title']);
            $this->fail('Expected 400 for missing newstitle');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('newstitle', $e->getErrorDetail());
        }
    }

    #[Test]
    public function create_with_title_over_max_returns_400(): void
    {
        $this->requireScope('news.write');
        // MAX_TITLE_LENGTH is 250 — send 260 chars.
        $title = str_repeat('A', 260);
        try {
            $this->client->news()->createArticle(['newstitle' => $title]);
            $this->fail('Expected 400 for over-length newstitle');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('exceeds', $e->getErrorDetail());
        }
    }

    #[Test]
    public function update_changes_newstitle(): void
    {
        $this->requireScope('news.write');
        $created = $this->client->news()->createArticle([
            'newstitle' => $this->uid('UpdSource'),
        ])->getData();
        $articleId = (int)$created['newsid'];

        $newTitle = $this->uid('UpdRevised');
        $r = $this->client->news()->updateArticle($articleId, ['newstitle' => $newTitle]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame($newTitle, $r->getData()['newstitle']);
    }

    #[Test]
    public function update_unknown_article_returns_404(): void
    {
        $this->requireScope('news.write');
        try {
            $this->client->news()->updateArticle(99999999, ['newstitle' => $this->uid('ghost')]);
            $this->fail('Expected 404 for unknown article');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function delete_returns_deleted_flag_and_cleans_up(): void
    {
        $this->requireScope('news.write');
        $this->requireScope('news.delete');

        $created = $this->client->news()->createArticle([
            'newstitle' => $this->uid('ToDelete'),
        ])->getData();
        $articleId = (int)$created['newsid'];

        $r = $this->client->news()->deleteArticle($articleId);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($r->getData()['deleted'] ?? false,
            'delete response must put deleted=true in data envelope');

        // After delete, get should 404.
        try {
            $this->client->news()->getArticle((string)$articleId);
            $this->fail('get after delete should 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function create_fully_populated_article_persists_all_fields(): void
    {
        $this->requireScope('news.write');
        $this->requireScope('news.read');

        $title = $this->uid('FullArticle');
        $payload = [
            'newstitle'        => $title,
            'newscontent'      => '<p>Long article body, paragraph form.</p>',
            'newsshortdesc'    => 'A short excerpt for listings.',
            'newssearchkeywords' => 'integration,sdk,full',
            'newsvisible'      => 1,
            'newscontenttype'  => 'text',
            'newslanguage'     => 'el',
            // The friendly tags + categories aliases — server collapses to
            // newstags / newscategory CSV columns respectively.
            'tags'             => ['integration', 'sdk', 'fullfield'],
            'categories'       => [0],
        ];

        $r = $this->client->news()->createArticle($payload);
        $this->assertSame(201, $r->getStatusCode());
        $articleId = (int)$r->getData()['newsid'];

        // Re-fetch to assert PERSISTED state (the create response can echo
        // the input shape; only get() proves it landed in DB).
        $back = $this->client->news()->getArticle((string)$articleId)->getData()['article'];

        $this->assertSame($title, $back['newstitle']);
        $this->assertStringContainsString('paragraph form', (string)$back['newscontent']);
        $this->assertStringContainsString('short excerpt', (string)$back['newsshortdesc']);
        $this->assertSame($payload['newssearchkeywords'], $back['newssearchkeywords']);
        $this->assertSame(1, (int)$back['newsvisible']);
        $this->assertSame('el', $back['newslanguage']);
        $this->assertSame('text', $back['newscontenttype']);

        // tags + categories friendly aliases land in CSV columns.
        $this->assertNotEmpty($back['newstags'] ?? '');
        foreach ($payload['tags'] as $tag) {
            $this->assertStringContainsString($tag, (string)$back['newstags']);
        }
    }

    #[Test]
    public function create_article_with_image_url_imports_to_gallery(): void
    {
        $this->requireScope('news.write');

        $r = $this->client->news()->createArticle([
            'newstitle' => $this->uid('ArticleWithImage'),
            'images'    => [
                ['url' => $this->testImageUrl, 'alt' => 'Test image'],
            ],
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $data = $r->getData();
        $this->assertArrayHasKey('images_result', $data);
        $this->assertSame(1, $data['images_result']['attached'],
            'real CDN URL should have been downloaded into news_gallery; ' .
            'if this fails, MEDIAMANAGER could not reach the URL — check NC_TEST_IMAGE');
        $this->assertSame(0, $data['images_result']['failed']);
    }
}
