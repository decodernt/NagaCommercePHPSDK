<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Modules;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the Modules SDK resource. Pins:
 *   - payment() → /system/modules/checkout/list/... (server uses the
 *     directory name `checkout`, not `payment`)
 *   - shipping() / analytics() / addons() route to the right URLs
 *   - default filter is 'enabled-configured' — the safe set for partners
 *     driving order-creation module selectors
 *   - bogus filter values throw InvalidArgumentException at the call site
 *     (rather than letting the server 404)
 */
final class ModulesTest extends TestCase
{
    private RecordingHttpClient $http;
    private Modules $modules;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->modules = new Modules($this->http);
    }

    #[Test]
    public function payment_routes_to_checkout_modules_list(): void
    {
        // Important: payment uses the `checkout` URL segment server-side —
        // that's where the actual module directory lives. Pinning so a
        // future "rename to /payment/list" doesn't silently break.
        $this->modules->payment();
        $req = $this->http->lastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/system/modules/checkout/list/enabled-configured', $req['path']);
    }

    #[Test]
    public function shipping_routes_to_shipping_modules_list(): void
    {
        $this->modules->shipping();
        $this->assertSame('/system/modules/shipping/list/enabled-configured', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function analytics_routes_to_analytics_modules_list(): void
    {
        $this->modules->analytics();
        $this->assertSame('/system/modules/analytics/list/enabled-configured', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function addons_routes_to_addons_list(): void
    {
        $this->modules->addons();
        $this->assertSame('/system/addons/list/enabled-configured', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function default_filter_is_enabled_configured(): void
    {
        // Without an explicit filter, the SDK asks for `enabled-configured`
        // — the set that the order-create validator also uses. Mismatching
        // these two would mean partners pick a module the create
        // endpoint then rejects.
        $this->modules->payment();
        $this->assertStringEndsWith('/enabled-configured', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function explicit_filter_values_pass_through(): void
    {
        $this->modules->payment('all');
        $this->assertSame('/system/modules/checkout/list/all', $this->http->lastRequest()['path']);

        $this->modules->shipping('enabled');
        $this->assertSame('/system/modules/shipping/list/enabled', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function invalid_filter_throws_at_call_site(): void
    {
        // The server's URL regex is `(all|enabled|enabled-configured)` —
        // anything else would 404. Surfacing that as an
        // InvalidArgumentException at the SDK boundary is friendlier.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Filter must be one of');
        $this->modules->payment('not-a-real-filter');
    }
}
