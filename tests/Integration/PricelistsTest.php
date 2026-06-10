<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * /pricelists endpoints — read-only.
 *
 * The list endpoint is API-key-scoped: only pricelists explicitly assigned
 * to the calling key (via api_key_price_lists) are returned. On a store
 * without any pricelists assigned to the test key, list() returns an empty
 * array — the suite handles that gracefully and skips the items() check.
 */
final class PricelistsTest extends IntegrationTestCase
{
    #[Test]
    public function list_returns_array(): void
    {
        $this->requireScope('pricelists.read');
        $r = $this->client->pricelists()->list();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    #[Test]
    public function items_for_first_list_paginates(): void
    {
        $this->requireScope('pricelists.read');
        $lists = $this->client->pricelists()->list()->getData();
        if (empty($lists)) {
            $this->markTestSkipped('No pricelists assigned to this API key — assign one via api_key_price_lists to run this test.');
        }
        $first = $lists[0];
        // The /pricelists/list endpoint exposes `pl_id` (matching the
        // underlying schema). `pricelistid` / `id` are alternates seen in
        // older shapes — checked for resilience but pl_id is canonical.
        $listId = (int)($first['pl_id'] ?? $first['pricelistid'] ?? $first['id'] ?? 0);
        $this->assertGreaterThan(0, $listId, 'pricelist row must expose an integer pl_id');
        $r = $this->client->pricelists()->items($listId, ['limit' => 5]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }
}
