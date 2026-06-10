<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Brands;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BrandsTest extends TestCase
{
    private RecordingHttpClient $http;
    private Brands $brands;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->brands = new Brands($this->http);
    }

    #[Test]
    public function list_hits_brands_list(): void
    {
        $this->brands->list();
        $this->assertSame('GET', $this->http->lastRequest()['method']);
        $this->assertSame('/brands/list', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function search_passes_query_params(): void
    {
        $this->brands->search(['search' => 'levi']);
        $r = $this->http->lastRequest();
        $this->assertSame('/brands/search', $r['path']);
        $this->assertSame(['search' => 'levi'], $r['query']);
    }

    #[Test]
    public function create_posts_to_brands_create(): void
    {
        $this->brands->create(['brandname' => 'X']);
        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/brands/create', $r['path']);
    }

    #[Test]
    public function update_PUTs_to_brand_subpath(): void
    {
        $this->brands->update(5, ['brandname' => 'X']);
        $r = $this->http->lastRequest();
        $this->assertSame('PUT', $r['method']);
        $this->assertSame('/brands/brand/5', $r['path']);
    }
}
