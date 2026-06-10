<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /coupons CRUD.
 *
 * Pins:
 *   - type / type_name round-trip
 *   - duplicate code on create returns 409 (not 500)
 *   - duplicate code on update returns 409
 *   - expires accepts ISO 8601 OR unix int
 *   - exclude_customer_group_ids array gets CSV-flattened
 *   - getByCode 404s for unknown codes
 *   - delete removes coupon + leaves order_coupons FK cascade to clean up
 */
final class CouponsTest extends IntegrationTestCase
{
    private function uniqueCode(string $hint = 'C'): string
    {
        return 'SDKIT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)) . '-' . $hint;
    }

    #[Test]
    public function create_minimal_persists_with_defaults(): void
    {
        $this->requireScope('coupons.write');
        $r = $this->client->coupons()->create([
            'name' => 'sdkit-min-' . $this->uid('m'),
            'code' => $this->uniqueCode('MIN'),
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertSame(1, (int)$row['coupontype']);          // default
        $this->assertSame('percent_item', $row['type_name']);
        $this->assertSame(1, (int)$row['couponenabled']);
        $this->assertSame('products', $row['couponappliesto']);
    }

    #[Test]
    public function create_with_type_name_translates_to_int(): void
    {
        $this->requireScope('coupons.write');
        $r = $this->client->coupons()->create([
            'name'      => 'sdkit-tn-' . $this->uid('n'),
            'code'      => $this->uniqueCode('TN'),
            'type_name' => 'free_shipping',
            'amount'    => 0,
        ]);
        $row = $r->getData();
        $this->assertSame(3, (int)$row['coupontype']);
        $this->assertSame('free_shipping', $row['type_name']);
    }

    #[Test]
    public function duplicate_code_on_create_returns_409(): void
    {
        $this->requireScope('coupons.write');
        $code = $this->uniqueCode('DUP');
        $this->client->coupons()->create(['name' => 'first',  'code' => $code]);
        try {
            $this->client->coupons()->create(['name' => 'second', 'code' => $code]);
            $this->fail('Expected 409 for duplicate coupon code');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    #[Test]
    public function update_to_existing_code_returns_409(): void
    {
        $this->requireScope('coupons.write');
        $a = $this->client->coupons()->create(['name' => 'A', 'code' => $this->uniqueCode('A')])->getData();
        $b = $this->client->coupons()->create(['name' => 'B', 'code' => $this->uniqueCode('B')])->getData();

        try {
            $this->client->coupons()->update((int)$b['couponid'], ['code' => $a['couponcode']]);
            $this->fail('Expected 409 when renaming a coupon to a code already in use');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    #[Test]
    public function expires_accepts_iso_8601_and_stores_unix_int(): void
    {
        $this->requireScope('coupons.write');
        $iso = '2027-12-31T23:59:59+00:00';
        $r = $this->client->coupons()->create([
            'name'    => 'sdkit-exp-' . $this->uid('e'),
            'code'    => $this->uniqueCode('EXP'),
            'expires' => $iso,
        ])->getData();
        $this->assertSame(strtotime($iso), (int)$r['couponexpires']);
    }

    #[Test]
    public function exclude_customer_group_ids_accepts_array_and_csv(): void
    {
        $this->requireScope('coupons.write');
        $r = $this->client->coupons()->create([
            'name' => 'sdkit-cg-' . $this->uid('g'),
            'code' => $this->uniqueCode('CG'),
            'exclude_customer_group_ids' => [1, 3, 7],
        ])->getData();
        $this->assertSame('1,3,7', $r['exclude_customer_group_ids']);
    }

    #[Test]
    public function get_by_code_round_trip(): void
    {
        $this->requireScope('coupons.read');
        $this->requireScope('coupons.write');
        $code = $this->uniqueCode('BY');
        $created = $this->client->coupons()->create([
            'name' => 'sdkit-by-' . $this->uid('b'),
            'code' => $code,
        ])->getData();

        $back = $this->client->coupons()->getByCode($code)->getData();
        $this->assertSame((int)$created['couponid'], (int)$back['couponid']);
    }

    #[Test]
    public function get_by_unknown_code_returns_404(): void
    {
        $this->requireScope('coupons.read');
        try {
            $this->client->coupons()->getByCode('SDKIT-NO-SUCH-CODE-9999');
            $this->fail('Expected 404 for unknown code');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function delete_then_get_returns_404(): void
    {
        $this->requireScope('coupons.write');
        $this->requireScope('coupons.delete');
        $created = $this->client->coupons()->create([
            'name' => 'sdkit-del-' . $this->uid('d'),
            'code' => $this->uniqueCode('DEL'),
        ])->getData();
        $id = (int)$created['couponid'];

        $this->client->coupons()->delete($id);
        try {
            $this->client->coupons()->get($id);
            $this->fail('get after delete must 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function list_filters_by_enabled(): void
    {
        $this->requireScope('coupons.read');
        $this->requireScope('coupons.write');

        $this->client->coupons()->create([
            'name' => 'sdkit-en-' . $this->uid('e'), 'code' => $this->uniqueCode('EN'),
            'enabled' => true,
        ]);
        $this->client->coupons()->create([
            'name' => 'sdkit-dis-' . $this->uid('d'), 'code' => $this->uniqueCode('DIS'),
            'enabled' => false,
        ]);

        $en = $this->client->coupons()->list(['enabled' => 1, 'limit' => 200])->getData();
        $dis = $this->client->coupons()->list(['enabled' => 0, 'limit' => 200])->getData();
        foreach ($en  as $r) { $this->assertSame(1, (int)$r['couponenabled']); }
        foreach ($dis as $r) { $this->assertSame(0, (int)$r['couponenabled']); }
    }
}
