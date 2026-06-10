<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * /reference/* endpoints — currencies, customer-groups, tax-classes,
 * availabilities. Read-only.
 *
 * Each test pins the response row shape that downstream write endpoints
 * depend on. If a column gets renamed server-side and the SDK shape
 * drifts, these fail first — before the field gets bound on a real
 * customer / product write.
 *
 * Skipped when the key lacks `reference.read`.
 */
final class ReferenceTest extends IntegrationTestCase
{
    #[Test]
    public function currencies_returns_normalized_rows(): void
    {
        $this->requireScope('reference.read');

        $rows = $this->client->reference()->currencies()->getData();
        $this->assertIsArray($rows);
        if (empty($rows)) {
            $this->markTestSkipped('store has no active currencies; cannot verify shape');
        }

        $row = $rows[0];
        $this->assertArrayHasKey('id',            $row);
        $this->assertArrayHasKey('code',          $row);
        $this->assertArrayHasKey('name',          $row);
        $this->assertArrayHasKey('symbol',        $row);
        $this->assertArrayHasKey('exchange_rate', $row);
        $this->assertArrayHasKey('decimals',      $row);
        $this->assertArrayHasKey('is_default',    $row);
        $this->assertArrayHasKey('is_active',     $row);

        $this->assertIsInt($row['id']);
        $this->assertIsBool($row['is_default']);
        $this->assertIsBool($row['is_active']);
        // Internal column names must NOT leak.
        $this->assertArrayNotHasKey('currencyid',        $row);
        $this->assertArrayNotHasKey('currencyisdefault', $row);
    }

    #[Test]
    public function customer_groups_returns_normalized_rows(): void
    {
        $this->requireScope('reference.read');

        $rows = $this->client->reference()->customerGroups()->getData();
        $this->assertIsArray($rows);
        if (empty($rows)) {
            $this->markTestSkipped('store has no customer groups');
        }
        foreach (['id', 'name', 'discount', 'discount_method', 'is_default'] as $k) {
            $this->assertArrayHasKey($k, $rows[0]);
        }
        $this->assertIsInt($rows[0]['id']);
        $this->assertArrayNotHasKey('customergroupid', $rows[0]);
        $this->assertArrayNotHasKey('groupname',       $rows[0]);
    }

    #[Test]
    public function tax_classes_returns_id_name_pairs(): void
    {
        $this->requireScope('reference.read');

        $rows = $this->client->reference()->taxClasses()->getData();
        $this->assertIsArray($rows);
        if (empty($rows)) {
            $this->markTestSkipped('store has no tax classes');
        }
        $this->assertSame(['id', 'name'], array_keys($rows[0]),
            'tax classes should only surface id + name, nothing else');
        $this->assertIsInt($rows[0]['id']);
    }

    #[Test]
    public function availabilities_carry_integer_id_for_writes(): void
    {
        // Pins the schema quirk: products.prodavailability stores the
        // AVAILID INTEGER (in a varchar column). Each row carries `id`
        // (the availid) — that's what downstream product writes accept
        // as availability_id. `title` is the internal language-key for
        // admin lookup only.
        $this->requireScope('reference.read');

        $rows = $this->client->reference()->availabilities()->getData();
        $this->assertIsArray($rows);
        if (empty($rows)) {
            $this->markTestSkipped('store has no product_availability rows');
        }
        $row = $rows[0];
        $this->assertArrayHasKey('id',         $row);
        $this->assertArrayHasKey('title',      $row);
        $this->assertArrayHasKey('color',      $row);
        $this->assertArrayHasKey('enabled',    $row);
        $this->assertArrayHasKey('sort_order', $row);

        $this->assertIsInt($row['id'], 'availability_id stored on products is the integer availid');
        $this->assertIsString($row['title']);
        $this->assertArrayNotHasKey('value', $row, 'old `value` key was removed');
    }
}
