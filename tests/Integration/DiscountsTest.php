<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /discounts CRUD.
 *
 * Pins:
 *   - config blob round-trips as both string and array
 *   - System-default rule cannot be deleted (409)
 *   - Update is partial
 *   - List filters by enabled + rule_type
 *   - expires/start_date accept ISO 8601
 *   - exclude_customer_group_ids array → CSV
 */
final class DiscountsTest extends IntegrationTestCase
{
    #[Test]
    public function create_then_get_round_trips_config_blob(): void
    {
        $this->requireScope('discounts.write');
        $this->requireScope('discounts.read');

        // Use ints + non-trailing-zero floats so JSON round-trip is exact:
        // PHP's json_encode emits 50.0 as "50", and round-tripping that
        // back gives an int — not a partner bug, just JSON.
        $config = [
            'threshold' => 50.25,
            'amount_off' => 10.75,
            'applies_to_categories' => [3, 7, 11],
        ];
        $r = $this->client->discounts()->create([
            'name'      => 'sdkit-d-' . $this->uid('d'),
            'rule_type' => 'rule_itemsaleprice',
            'config'    => $config,
            'enabled'   => true,
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertSame('rule_itemsaleprice', $row['discountruletype']);
        $this->assertSame(1, (int)$row['discountenabled']);
        // configdata is the raw JSON string; config is the decoded array.
        $this->assertSame(json_encode($config, JSON_UNESCAPED_UNICODE), $row['configdata']);
        $this->assertSame($config, $row['config']);

        // Re-fetch via get() — must still decode.
        $back = $this->client->discounts()->get((int)$row['discountid'])->getData();
        $this->assertSame($config, $back['config']);
    }

    #[Test]
    public function delete_system_default_rule_returns_409(): void
    {
        $this->requireScope('discounts.read');
        $this->requireScope('discounts.delete');
        $rows = $this->client->discounts()->list(['limit' => 200])->getData();
        $sysDefault = array_values(array_filter($rows, fn($r) => (int)$r['system_default'] === 1));
        if (empty($sysDefault)) {
            $this->markTestSkipped('store has no system_default rule on which to verify the 409 contract');
        }
        $id = (int)$sysDefault[0]['discountid'];

        try {
            $this->client->discounts()->delete($id);
            $this->fail('system_default rules must NOT be deletable');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    #[Test]
    public function partial_update_changes_only_sent_fields(): void
    {
        $this->requireScope('discounts.write');
        $created = $this->client->discounts()->create([
            'name'       => 'sdkit-up-' . $this->uid('u'),
            'rule_type'  => 'rule_itemsaleprice',
            'enabled'    => true,
            'sort_order' => 5,
            'config'     => ['key' => 'orig'],
        ])->getData();
        $id = (int)$created['discountid'];

        $r = $this->client->discounts()->update($id, ['enabled' => false]);
        $back = $r->getData();
        $this->assertSame(0, (int)$back['discountenabled']);
        $this->assertSame(5, (int)$back['sortorder']); // untouched
        $this->assertSame(['key' => 'orig'], $back['config']);   // untouched
    }

    #[Test]
    public function expires_accepts_iso_8601(): void
    {
        $this->requireScope('discounts.write');
        $iso = '2027-06-30T00:00:00+00:00';
        $r = $this->client->discounts()->create([
            'name'      => 'sdkit-exp-' . $this->uid('e'),
            'rule_type' => 'rule_itemsaleprice',
            'expires'   => $iso,
            'start_date'=> $iso,
        ])->getData();
        $this->assertSame(strtotime($iso), (int)$r['discountexpiry']);
        $this->assertSame(strtotime($iso), (int)$r['discountstartdate']);
    }

    #[Test]
    public function exclude_customer_group_ids_array_becomes_csv(): void
    {
        $this->requireScope('discounts.write');
        $r = $this->client->discounts()->create([
            'name'      => 'sdkit-cg-' . $this->uid('g'),
            'rule_type' => 'rule_itemsaleprice',
            'exclude_customer_group_ids' => [1, 3, 7],
            'apply_in_price_lists'       => [11, 22],
        ])->getData();
        $this->assertSame('1,3,7', $r['exclude_customer_group_ids']);
        $this->assertSame('11,22', $r['apply_in_price_lists']);
    }

    #[Test]
    public function list_filters_by_rule_type(): void
    {
        $this->requireScope('discounts.read');
        $this->requireScope('discounts.write');

        $marker = 'rule_itemsaleprice'; // existing rule_type — always in the install
        $rows = $this->client->discounts()->list(['rule_type' => $marker, 'limit' => 200])->getData();
        foreach ($rows as $r) {
            $this->assertSame($marker, $r['discountruletype']);
        }
    }
}
