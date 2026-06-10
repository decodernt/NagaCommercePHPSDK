<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Pre-flight: confirm the env is wired correctly and the live API answers.
 * This is the test you want to PASS before any of the others can be
 * trusted — auth failure here is "your env vars are wrong", not "the
 * feature is broken".
 */
final class SmokeTest extends IntegrationTestCase
{
    #[Test]
    public function sync_verify_returns_connected_true(): void
    {
        $r = $this->client->sync()->verify();
        $this->assertSame(200, $r->getStatusCode(), 'sync/verify must return 200; check NC_API_URL and NC_API_KEY');

        $data = $r->getData();
        $this->assertTrue(!empty($data['connected']), 'sync/verify must return connected=true');
        $this->assertNotEmpty($data['store_name'], 'store_name should be populated');
        $this->assertNotEmpty($data['scopes'], 'API key should have at least one scope');
    }
}
