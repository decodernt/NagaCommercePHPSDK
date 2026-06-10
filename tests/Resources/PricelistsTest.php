<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Pricelists;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PricelistsTest extends TestCase
{
    private RecordingHttpClient $http;
    private Pricelists $pricelists;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->pricelists = new Pricelists($this->http);
    }

    #[Test]
    public function list_hits_pricelists_list(): void
    {
        $this->pricelists->list();
        $this->assertSame('/pricelists/list', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function items_uses_pricelist_id_subpath_and_passes_pagination(): void
    {
        $this->pricelists->items(7, ['start' => 0, 'limit' => 50]);
        $r = $this->http->lastRequest();
        $this->assertSame('/pricelists/pricelist/7/items', $r['path']);
        $this->assertSame(['start' => 0, 'limit' => 50], $r['query']);
    }
}
