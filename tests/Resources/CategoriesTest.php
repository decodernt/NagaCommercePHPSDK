<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Categories;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CategoriesTest extends TestCase
{
    private RecordingHttpClient $http;
    private Categories $categories;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->categories = new Categories($this->http);
    }

    #[Test]
    public function list_hits_categories_list(): void
    {
        $this->categories->list();
        $this->assertSame('/categories/list', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function search_hits_categories_search(): void
    {
        $this->categories->search(['search' => 'bag']);
        $r = $this->http->lastRequest();
        $this->assertSame('/categories/search', $r['path']);
        $this->assertSame(['search' => 'bag'], $r['query']);
    }

    #[Test]
    public function get_uses_category_subpath(): void
    {
        $this->categories->get(5);
        $this->assertSame('/categories/category/5', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function create_posts_to_categories_create(): void
    {
        $this->categories->create(['catname' => 'X']);
        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/categories/create', $r['path']);
    }

    #[Test]
    public function update_PUTs_to_category_subpath(): void
    {
        $this->categories->update(5, ['catname' => 'X']);
        $r = $this->http->lastRequest();
        $this->assertSame('PUT', $r['method']);
        $this->assertSame('/categories/category/5', $r['path']);
    }

    #[Test]
    public function batch_create_posts_categories_array(): void
    {
        $rows = [
            ['ref' => 'bags',    'catname' => 'Bags'],
            ['ref' => 'wallets', 'catname' => 'Wallets', 'parent_ref' => 'bags'],
            ['catname' => 'Coin Purses', 'parent_ref' => 'wallets'],
            ['catname' => 'Belts', 'catparentid' => 42],
        ];
        $this->categories->batchCreate($rows);

        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/categories/batch/create', $r['path']);
        // Payload wraps the rows under `categories` — server reads it as
        // $json['categories'].
        $this->assertSame(['categories' => $rows], $r['body']);
    }
}
