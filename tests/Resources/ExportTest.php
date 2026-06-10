<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Export;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExportTest extends TestCase
{
    private RecordingHttpClient $http;
    private Export $export;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->export = new Export($this->http);
    }

    #[Test]
    public function products_POSTs_with_page_and_per_page_on_query(): void
    {
        $this->export->products(2, 50);
        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/export/products/', $r['path']);
        // Pagination is on the query string because the server reads from $_GET.
        $this->assertSame(['page' => '2', 'per_page' => '50', 'format' => 'json'], $r['query']);
    }

    #[Test]
    public function products_with_filters_wraps_them_under_filters_filter(): void
    {
        $filters = [
            ['field' => 'prodvisible', 'type' => 'is', 'value' => 1],
        ];
        $this->export->products(1, 100, $filters);
        $r = $this->http->lastRequest();
        $this->assertSame(
            ['filter' => $filters],
            $r['body']['filters']
        );
    }

    #[Test]
    public function products_with_price_list_ids_sends_them_in_body(): void
    {
        $this->export->products(1, 100, [], [3, 7]);
        $this->assertSame([3, 7], $this->http->lastRequest()['body']['price_list_ids']);
    }

    #[Test]
    public function products_without_filters_or_pricelists_still_posts(): void
    {
        // POST-only matters: the server's `match('GET|POST', ...)` accepts
        // both but we always send POST so adding filters later doesn't change
        // the HTTP verb mid-flight.
        $this->export->products();
        $this->assertSame('POST', $this->http->lastRequest()['method']);
    }
}
