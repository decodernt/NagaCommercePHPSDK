<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /system/modules/* + /system/addons/* discovery + addon toggle.
 *
 * Scope migration: list endpoints accept either `modules.read` (new) or
 * `system.settings` (legacy). Addon enable/disable accepts either
 * `modules.write` or `system.settings`. Tests skip cleanly when the key
 * has neither.
 *
 * Pins:
 *   - the four module-type listing endpoints exist + answer 200
 *   - `payment()` routes to /checkout/ (the server's directory name)
 *   - response is a list — partners iterate it to pick `id` for
 *     order-create's payment_method / shipping.module
 *   - the live `object` instance field is NOT in the payload (it's a
 *     loaded module class, never safe over the wire)
 *   - addon enable/disable is idempotent (changed=false when no-op)
 */
final class ModulesTest extends IntegrationTestCase
{
    private const READ_SCOPES = ['modules.read', 'system.settings'];
    private const WRITE_SCOPES = ['modules.write', 'system.settings'];

    #[Test]
    public function payment_modules_list_has_expected_shape(): void
    {
        $this->requireAnyScope(self::READ_SCOPES);

        $rows = $this->client->modules()->payment()->getData();
        $this->assertIsArray($rows);

        // The store may have zero enabled-configured payment modules in
        // a clean install — that's a valid state, not a test failure.
        if (empty($rows)) {
            $this->markTestSkipped('store has no enabled+configured payment modules');
        }

        $row = $rows[0];
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('enabled', $row);
        // The live module instance must NEVER appear in the payload.
        $this->assertArrayNotHasKey('object', $row,
            '`object` is a live class instance; surfacing it would leak internal state + break JSON encoding');
    }

    #[Test]
    public function shipping_modules_list_works(): void
    {
        $this->requireAnyScope(self::READ_SCOPES);
        $rows = $this->client->modules()->shipping()->getData();
        $this->assertIsArray($rows);
        if (!empty($rows)) {
            $this->assertArrayNotHasKey('object', $rows[0]);
        }
    }

    #[Test]
    public function analytics_modules_list_works(): void
    {
        $this->requireAnyScope(self::READ_SCOPES);
        $rows = $this->client->modules()->analytics()->getData();
        $this->assertIsArray($rows);
    }

    #[Test]
    public function addons_list_works(): void
    {
        $this->requireAnyScope(self::READ_SCOPES);
        $rows = $this->client->modules()->addons()->getData();
        $this->assertIsArray($rows);
    }

    #[Test]
    public function enable_then_disable_addon_is_idempotent(): void
    {
        $this->requireAnyScope(self::WRITE_SCOPES);

        // Pick an existing (probably-disabled) addon — fall back to a
        // synthetic id when the store has none, so we still exercise the
        // toggle path.
        $addons = [];
        try {
            $addons = $this->client->modules()->addons('all')->getData();
        } catch (ApiException $e) {
            $this->markTestSkipped('addons listing unavailable on this store: ' . $e->getMessage());
        }
        $candidate = null;
        foreach ($addons as $a) {
            if (!empty($a['id'])) { $candidate = (string)$a['id']; break; }
        }
        if ($candidate === null) {
            $candidate = 'sdkit-no-such-addon';  // synthetic, addon doesn't have to exist for toggle to round-trip
        }

        // First disable to normalize starting state — server is
        // idempotent so this is safe even if it was already off.
        $this->client->modules()->disableAddon($candidate);

        $r = $this->client->modules()->enableAddon($candidate);
        $this->assertSame(200, $r->getStatusCode());
        $d = $r->getData();
        $this->assertSame($candidate, $d['addon_id']);
        $this->assertTrue($d['enabled']);
        $this->assertTrue($d['changed'],
            'addon was off, enabling must report changed=true');

        // Second enable is a no-op.
        $again = $this->client->modules()->enableAddon($candidate)->getData();
        $this->assertFalse($again['changed'],
            'idempotent — re-enabling an already-on addon must report changed=false');

        // Cleanup so we leave the store roughly where we found it.
        $this->client->modules()->disableAddon($candidate);
    }
}
