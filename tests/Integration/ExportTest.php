<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * /export/products — heavy paginated catalog dump.
 *
 * Quirks the SDK abstracts:
 *  - Pagination uses page + per_page (NOT start + limit)
 *  - Route matches both GET and POST; we always POST so the filter DSL
 *    body can ride along
 *  - Filters are nested: { filters: { filter: [{ field, type, value }, ...] } }
 *
 * Each row carries custom_fields, options, prices, tags — different shape
 * from /products/find.
 */
final class ExportTest extends IntegrationTestCase
{
    #[Test]
    public function page_1_per_page_5_returns_rows(): void
    {
        $this->requireScope('products.export');
        $r = $this->client->export()->products(1, 5);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    #[Test]
    public function filter_by_visible_true_returns_only_visible_products(): void
    {
        $this->requireScope('products.export');
        $r = $this->client->export()->products(1, 10, [
            ['field' => 'prodvisible', 'type' => 'is', 'value' => '1'],
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $rows = $r->getData();
        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertSame(1, (int)($row['prodvisible'] ?? 0),
                'filter prodvisible=1 must only return visible rows');
        }
    }

    #[Test]
    public function filter_by_unknown_brand_returns_empty(): void
    {
        $this->requireScope('products.export');
        $r = $this->client->export()->products(1, 10, [
            ['field' => 'prodbrandid', 'type' => 'is', 'value' => '99999999'],
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame([], $r->getData(),
            'filtering by a non-existent brand id should return zero rows');
    }
}
