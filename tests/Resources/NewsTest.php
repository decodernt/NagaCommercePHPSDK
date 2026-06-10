<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\News;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class NewsTest extends TestCase
{
    private RecordingHttpClient $http;
    private News $news;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->news = new News($this->http);
    }

    #[Test]
    public function list_articles_without_category_hits_root_list(): void
    {
        $this->news->listArticles();
        $this->assertSame('/articles/list', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function list_articles_with_category_appends_slug(): void
    {
        $this->news->listArticles('press');
        $this->assertSame('/articles/list/press', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function get_article_uses_article_subpath(): void
    {
        $this->news->getArticle('our-new-product');
        $this->assertSame('/articles/article/our-new-product', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function search_articles_appends_term_to_find_subpath(): void
    {
        $this->news->searchArticles('summer sale');
        // The server reads the term from the path segment (not query string),
        // so it has to be url-encoded.
        $this->assertSame('/articles/find/summer%20sale', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function list_categories_hits_news_categories_list(): void
    {
        $this->news->listCategories();
        $this->assertSame('/news-categories/list', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function get_category_uses_category_subpath(): void
    {
        $this->news->getCategory('press');
        $this->assertSame('/news-categories/category/press', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function search_categories_uses_find_with_query(): void
    {
        $this->news->searchCategories(['search' => 'event']);
        $r = $this->http->lastRequest();
        $this->assertSame('/news-categories/find', $r['path']);
        $this->assertSame(['search' => 'event'], $r['query']);
    }

    // -- create / update / delete -----------------------------------------

    #[Test]
    public function create_article_posts_to_articles_create(): void
    {
        $payload = [
            'newstitle'   => 'Big Announcement',
            'newscontent' => '<p>Body</p>',
            'newsvisible' => 1,
            'categories'  => [3, 7],
            'images'      => [
                ['url' => 'https://cdn.example.com/hero.jpg', 'alt' => 'Hero'],
            ],
        ];
        $this->news->createArticle($payload);

        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/articles/create', $r['path']);
        $this->assertSame($payload, $r['body']);
    }

    #[Test]
    public function update_article_PUTs_to_article_subpath(): void
    {
        $this->news->updateArticle(500, ['newstitle' => 'Updated']);
        $r = $this->http->lastRequest();
        $this->assertSame('PUT', $r['method']);
        $this->assertSame('/articles/article/500', $r['path']);
        $this->assertSame(['newstitle' => 'Updated'], $r['body']);
    }

    #[Test]
    public function delete_article_DELETEs_article_subpath(): void
    {
        $this->news->deleteArticle(500);
        $r = $this->http->lastRequest();
        $this->assertSame('DELETE', $r['method']);
        $this->assertSame('/articles/article/500', $r['path']);
    }
}
