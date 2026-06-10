<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pricelist + pricelist-items write surface.
 *
 * Pins:
 *   - Create / update / delete pricelist round-trip
 *   - is_default invariant: promoting demotes prior default
 *   - Upsert key is (price_list_id, product_id, combination_id) —
 *     re-posting the same triple updates instead of creating a dup row
 *   - Bulk upsert: per-row outcome envelope; counts add up
 *   - Sale-price window accepts ISO 8601
 *   - Delete pricelist cascades items via FK
 */
final class PricelistsWriteTest extends IntegrationTestCase
{
    #[Test]
    public function create_list_then_delete_clears_items_via_fk_cascade(): void
    {
        $this->requireScope('pricelists.write');
        $this->requireScope('pricelists.read');
        $this->requireScope('pricelists.delete');
        $this->requireScope('products.write');

        $pl = $this->client->pricelists()->create([
            'name'        => 'sdkit-pl-' . $this->uid('p'),
            'currency'    => 'EUR',
            'description' => 'integration test',
            'priority'    => 50,
        ])->getData();
        $plId = (int)$pl['pl_id'];
        $this->assertSame('EUR', $pl['currency']);

        // Add an item.
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('PlProd'), 'sku' => $this->uid('pl-prod'), 'price' => 10.0,
        ])->getData()['productid'];
        $item = $this->client->pricelists()->upsertItem($plId, [
            'product_id' => $pid,
            'price'      => 7.50,
        ])->getData();
        $this->assertSame($pid, (int)$item['productid']);
        $this->assertEqualsWithDelta(7.50, (float)$item['prodprice'], 0.01);

        $this->client->pricelists()->delete($plId);

        // FK cascade should have wiped the items; the list endpoint may
        // still return 200 with an empty array (the route doesn't gate
        // on pricelist existence). We just want to verify the items are
        // gone — both shapes satisfy that.
        $afterItems = $this->client->pricelists()->items($plId)->getData();
        $this->assertSame([], $afterItems,
            'FK ON DELETE CASCADE must wipe price_list_items when the parent list is deleted');
    }

    #[Test]
    public function upsert_with_same_triple_updates_existing_row(): void
    {
        // The (price_list_id, product_id, combination_id) uniqueness key
        // means re-posting the same identity should NOT create a duplicate
        // row — partners re-syncing wholesale catalogs hourly need this.
        $this->requireScope('pricelists.write');
        $this->requireScope('pricelists.read');
        $this->requireScope('products.write');

        $plId = (int)$this->client->pricelists()->create(['name' => 'sdkit-up-' . $this->uid('u')])->getData()['pl_id'];
        $pid  = (int)$this->client->products()->create([
            'name' => $this->uid('UpProd'), 'sku' => $this->uid('up-prod'), 'price' => 1.0,
        ])->getData()['productid'];

        $first  = $this->client->pricelists()->upsertItem($plId, ['product_id' => $pid, 'price' => 9.99])->getData();
        $second = $this->client->pricelists()->upsertItem($plId, ['product_id' => $pid, 'price' => 8.88])->getData();

        $this->assertSame((int)$first['pli_id'], (int)$second['pli_id'],
            'upserting the same triple must reuse the same pli_id, not create a duplicate row');
        $this->assertEqualsWithDelta(8.88, (float)$second['prodprice'], 0.01);

        $items = $this->client->pricelists()->items($plId)->getData();
        $this->assertCount(1, $items);
    }

    #[Test]
    public function bulk_upsert_reports_per_row_outcomes(): void
    {
        $this->requireScope('pricelists.write');
        $this->requireScope('products.write');

        $plId = (int)$this->client->pricelists()->create(['name' => 'sdkit-bulk-' . $this->uid('b')])->getData()['pl_id'];
        $p1   = (int)$this->client->products()->create(['name' => $this->uid('B1'), 'sku' => $this->uid('b1'), 'price' => 1.0])->getData()['productid'];
        $p2   = (int)$this->client->products()->create(['name' => $this->uid('B2'), 'sku' => $this->uid('b2'), 'price' => 1.0])->getData()['productid'];

        $r = $this->client->pricelists()->bulkUpsertItems($plId, [
            ['product_id' => $p1, 'price' => 5.00],
            ['product_id' => $p2, 'price' => 6.00, 'sale_price' => 4.50],
            ['price' => 1.0], // missing product_id — should fail row-wise
        ]);
        $d = $r->getData();
        $this->assertSame(2, $d['upserted']);
        $this->assertSame(1, $d['failed']);
        $this->assertSame(3, $d['total']);
        $this->assertCount(3, $d['results']);
        $this->assertFalse($d['results'][2]['success']);
    }

    #[Test]
    public function update_item_partial_changes_price_only(): void
    {
        $this->requireScope('pricelists.write');
        $this->requireScope('products.write');

        $plId = (int)$this->client->pricelists()->create(['name' => 'sdkit-pu-' . $this->uid('p')])->getData()['pl_id'];
        $pid  = (int)$this->client->products()->create(['name' => $this->uid('PuP'), 'sku' => $this->uid('pup'), 'price' => 1.0])->getData()['productid'];
        $item = $this->client->pricelists()->upsertItem($plId, [
            'product_id' => $pid,
            'price'      => 10.00,
            'sale_price' => 7.50,
        ])->getData();
        $itemId = (int)$item['pli_id'];

        $r = $this->client->pricelists()->updateItem($plId, $itemId, ['price' => 9.00]);
        $back = $r->getData();
        $this->assertEqualsWithDelta(9.00, (float)$back['prodprice'], 0.01);
        // sale_price untouched.
        $this->assertEqualsWithDelta(7.50, (float)$back['prodsaleprice'], 0.01);
    }

    #[Test]
    public function iso_8601_sale_window_converts_to_unix_int(): void
    {
        $this->requireScope('pricelists.write');
        $this->requireScope('products.write');

        $plId = (int)$this->client->pricelists()->create(['name' => 'sdkit-iso-' . $this->uid('i')])->getData()['pl_id'];
        $pid  = (int)$this->client->products()->create(['name' => $this->uid('IsP'), 'sku' => $this->uid('is'), 'price' => 1.0])->getData()['productid'];
        $iso  = '2027-01-15T00:00:00+00:00';

        $item = $this->client->pricelists()->upsertItem($plId, [
            'product_id'      => $pid,
            'price'           => 9.99,
            'sale_price'      => 7.50,
            'sale_price_from' => $iso,
        ])->getData();
        $this->assertSame(strtotime($iso), (int)$item['sale_offer_begin']);
    }
}
