<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * /sync endpoints — connection + entity-count metadata. Already verified
 * connection in SmokeTest; here we pin the response shape across both
 * sync routes against the live store.
 */
final class SyncTest extends IntegrationTestCase
{
    #[Test]
    public function verify_returns_full_capabilities_envelope(): void
    {
        $data = $this->client->sync()->verify()->getData();

        // Required envelope keys (a missing one means the sync endpoint
        // got refactored without updating documented contract).
        $this->assertArrayHasKey('connected',    $data);
        $this->assertArrayHasKey('store_name',   $data);
        $this->assertArrayHasKey('store_url',    $data);
        $this->assertArrayHasKey('platform',     $data);
        $this->assertArrayHasKey('api_version',  $data);
        $this->assertArrayHasKey('scopes',       $data);
        $this->assertArrayHasKey('capabilities', $data);

        $this->assertSame('nagacommerce', $data['platform']);
        $this->assertIsArray($data['scopes']);
        $this->assertIsArray($data['capabilities']);

        // capabilities shape: known resources are boolean-keyed.
        foreach (['products', 'categories', 'brands', 'orders', 'customers'] as $resource) {
            $this->assertArrayHasKey($resource, $data['capabilities'], "missing capability flag: $resource");
            $this->assertIsBool($data['capabilities'][$resource]);
        }
    }

    #[Test]
    public function status_returns_entity_counts_with_last_modified(): void
    {
        $data = $this->client->sync()->status()->getData();

        $this->assertArrayHasKey('store_name', $data);
        $this->assertArrayHasKey('entities',   $data);
        $this->assertArrayHasKey('timestamp',  $data);

        // Each tracked entity must report at least `count`. Products and
        // orders additionally carry `last_modified` (the field the
        // incremental-sync flows depend on).
        foreach (['products', 'categories', 'brands', 'orders', 'customers'] as $resource) {
            $this->assertArrayHasKey($resource, $data['entities'], "entities.$resource missing");
            $this->assertArrayHasKey('count', $data['entities'][$resource]);
            $this->assertIsInt($data['entities'][$resource]['count']);
        }
        $this->assertArrayHasKey('last_modified', $data['entities']['products']);
        $this->assertArrayHasKey('last_modified', $data['entities']['orders']);

        // The timestamp must be in a sensible recent range — catches a
        // dev DB with clock skew or a stale-config artifact.
        $this->assertGreaterThan(time() - 60, $data['timestamp']);
        $this->assertLessThanOrEqual(time() + 60, $data['timestamp']);
    }
}
