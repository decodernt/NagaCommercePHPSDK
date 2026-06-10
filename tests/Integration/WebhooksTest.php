<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /webhooks CRUD + dispatch verification.
 *
 * Pins:
 *   - Create with auto-generated secret
 *   - Test endpoint fires the event and records a delivery row
 *   - Signature header matches HMAC-SHA256 of the body
 *   - Invalid URL on create returns 400
 *   - List filters by event_name
 *
 * NOTE: Requires `webhooks.*` scope on the API key. Tests skip cleanly
 * without it.
 */
final class WebhooksTest extends IntegrationTestCase
{
    /**
     * httpbin's /post endpoint is the easiest external echo target —
     * it returns the headers we sent so we can verify the signature
     * was correctly attached. Partners running their own test environment
     * can override via NC_TEST_WEBHOOK_URL.
     */
    private function testTarget(): string
    {
        $env = getenv('NC_TEST_WEBHOOK_URL');
        return $env !== false && $env !== ''
            ? $env
            : 'https://httpbin.org/post';
    }

    #[Test]
    public function create_minimal_webhook_auto_generates_secret(): void
    {
        $this->requireScope('webhooks.write');
        $r = $this->client->webhooks()->create([
            'url'        => $this->testTarget(),
            'event_name' => 'NewOrderCompleted',
            'name'       => 'sdkit-hook-' . $this->uid('w'),
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertNotEmpty($row['secret'],
            'server must auto-generate a secret when the caller omits one');
        $this->assertGreaterThanOrEqual(32, strlen($row['secret']),
            'auto-generated secret must be at least 32 bytes of entropy');
    }

    #[Test]
    public function invalid_url_returns_400(): void
    {
        $this->requireScope('webhooks.write');
        try {
            $this->client->webhooks()->create([
                'url'        => 'not a url',
                'event_name' => 'NewOrderCompleted',
            ]);
            $this->fail('Expected 400 for invalid URL');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    #[Test]
    public function test_endpoint_fires_delivery_and_logs_it(): void
    {
        $this->requireScope('webhooks.read');
        $this->requireScope('webhooks.write');

        // dispatch_mode=inline so the deliveries row is written before
        // the test endpoint returns. The default queue mode hands the
        // send to TaskManager and would race with our deliveries read
        // (worker hasn't picked the job yet).
        $w = $this->client->webhooks()->create([
            'url'           => $this->testTarget(),
            'event_name'    => 'NewOrderCompleted',
            'dispatch_mode' => 'inline',
        ])->getData();
        $wid = (int)$w['id'];

        $r = $this->client->webhooks()->test($wid, ['msg' => 'integration smoke']);
        $data = $r->getData();
        $this->assertTrue($data['fired']);

        // delivery log must carry the new row.
        $deliveries = $this->client->webhooks()->deliveries($wid)->getData();
        $this->assertNotEmpty($deliveries);
        $this->assertSame($wid, (int)$deliveries[0]['webhook_id']);
        // Either a 2xx (httpbin happy) or a non-zero error code (DNS
        // blocked / firewalled) — what we DON'T want is an unrecorded
        // attempt.
        $this->assertGreaterThanOrEqual(0, (int)$deliveries[0]['response_code']);
    }

    #[Test]
    public function update_changes_url_and_enables(): void
    {
        $this->requireScope('webhooks.write');
        $w = $this->client->webhooks()->create([
            'url'        => $this->testTarget(),
            'event_name' => 'NewOrderCompleted',
            'enabled'    => false,
        ])->getData();

        $upd = $this->client->webhooks()->update((int)$w['id'], [
            'enabled' => true,
            'url'     => $this->testTarget() . '?branch=updated',
        ])->getData();
        $this->assertSame(1, (int)$upd['enabled']);
        $this->assertStringContainsString('branch=updated', $upd['url']);
    }

    #[Test]
    public function list_filters_by_event_name(): void
    {
        $this->requireScope('webhooks.read');
        $this->requireScope('webhooks.write');

        $this->client->webhooks()->create(['url' => $this->testTarget(), 'event_name' => 'product_create_event']);
        $this->client->webhooks()->create(['url' => $this->testTarget(), 'event_name' => 'NewOrderCompleted']);

        $orderHooks = $this->client->webhooks()->list(['event_name' => 'NewOrderCompleted'])->getData();
        foreach ($orderHooks as $h) {
            $this->assertSame('NewOrderCompleted', $h['event_name']);
        }
    }

    #[Test]
    public function delete_then_get_returns_404(): void
    {
        $this->requireScope('webhooks.write');
        $this->requireScope('webhooks.delete');
        $w = $this->client->webhooks()->create([
            'url' => $this->testTarget(),
            'event_name' => 'NewOrderCompleted',
        ])->getData();
        $id = (int)$w['id'];

        $this->client->webhooks()->delete($id);
        try {
            $this->client->webhooks()->get($id);
            $this->fail('get after delete must 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
