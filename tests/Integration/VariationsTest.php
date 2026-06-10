<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Variations surface — options, option values, option sets, and per-product
 * variation combinations. Walks the full partner workflow end-to-end:
 *
 *   1. Create options (Size, Color) and add values to each
 *   2. Create an option set, then add options to it (idempotent)
 *   3. Create a variable product and assign the option set
 *   4. Create concrete combinations (Small+Red, Small+Blue, …) with
 *      SKU + price + stock per combination
 *   5. Update one combination's stock
 *   6. Delete one combination
 *   7. Clean teardown via the cleanup endpoints (option set delete also
 *      zero-points the product's prodoptionsetid)
 *
 * Also pins:
 *   - Listing endpoints return arrays even on cold stores
 *   - addOptionToSet is idempotent (`added: false` on no-op)
 *   - Variation create REQUIRES `value_ids`
 *   - Variation create REQUIRES an option set (either inline or on product)
 *   - Cross-product variation update returns 404 (no cross-tenant edits)
 */
final class VariationsTest extends IntegrationTestCase
{
    #[Test]
    public function list_options_returns_array(): void
    {
        $this->requireScope('products.read');
        $r = $this->client->products()->listOptions();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    #[Test]
    public function list_option_sets_returns_array(): void
    {
        $this->requireScope('products.read');
        $r = $this->client->products()->listOptionSets();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    #[Test]
    public function full_variation_workflow_end_to_end(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        // 1. Create two options + values per option.
        $sizeOpt = $this->client->products()->createOption([
            'name'         => 'Size_' . $this->uid('opt'),
            'display_name' => 'Size',
            'required'     => true,
            'is_size'      => true,
        ])->getData();
        $sizeOptId = (int)$sizeOpt['optionid'];
        $this->assertSame(1, (int)$sizeOpt['isSize']);

        $colorOpt = $this->client->products()->createOption([
            'name'         => 'Color_' . $this->uid('opt'),
            'display_name' => 'Color',
            'is_color'     => true,
        ])->getData();
        $colorOptId = (int)$colorOpt['optionid'];

        $small = (int)$this->client->products()->createOptionValue($sizeOptId, ['value' => 'Small'])->getData()['valueid'];
        $large = (int)$this->client->products()->createOptionValue($sizeOptId, ['value' => 'Large', 'sort_order' => 2])->getData()['valueid'];

        $red  = (int)$this->client->products()->createOptionValue($colorOptId, [
            'value'  => 'Red',
            'extras' => ['type' => 'onecolor', 'onecolor' => '#ff0000'],
        ])->getData()['valueid'];
        $blue = (int)$this->client->products()->createOptionValue($colorOptId, [
            'value'  => 'Blue',
            'extras' => ['type' => 'onecolor', 'onecolor' => '#0000ff'],
        ])->getData()['valueid'];

        // listOptionValues returns the 2 we added per option.
        $sizeVals = $this->client->products()->listOptionValues($sizeOptId)->getData();
        $this->assertCount(2, $sizeVals);

        // The optionExtras column should carry the color JSON we sent.
        $colorVals = $this->client->products()->listOptionValues($colorOptId)->getData();
        $foundColored = false;
        foreach ($colorVals as $v) {
            $decoded = json_decode((string)$v['optionExtras'], true);
            if (is_array($decoded) && ($decoded['type'] ?? '') === 'onecolor') {
                $foundColored = true;
                break;
            }
        }
        $this->assertTrue($foundColored, 'optionExtras must round-trip the color metadata we sent on create');

        // 2. Create an option set with one option, then ADD the second
        // (exercises both inline + after-the-fact wiring paths).
        $set = $this->client->products()->createOptionSet([
            'name'       => 'Set_' . $this->uid('os'),
            'option_ids' => [$sizeOptId],
        ])->getData();
        $setId = (int)$set['optionsetid'];
        $this->assertCount(1, $set['options']);

        $afterAdd = $this->client->products()->addOptionToSet($setId, $colorOptId)->getData();
        $this->assertCount(2, $afterAdd['options'],
            'addOptionToSet should leave the set with both options');

        // Idempotent: adding again is a no-op.
        $again = $this->client->products()->addOptionToSet($setId, $colorOptId);
        $this->assertSame(200, $again->getStatusCode());
        $this->assertFalse($again->getMeta()['added'] ?? true,
            're-adding an option to a set must report meta.added=false');

        // 3. Create a variable product and link the option set.
        $product = $this->client->products()->create([
            'name'            => $this->uid('VariableProduct'),
            'sku'             => $this->uid('var-sku'),
            'price'           => 29.99,
            'visible'         => 1,
            'inventory_track' => 2, // 2 = per-variation stock tracking
            'option_set_id'   => $setId,
        ])->getData();
        $productId = (int)$product['productid'];
        $this->assertSame($setId, (int)$product['prodoptionsetid'],
            'option_set_id on the create payload must persist to prodoptionsetid');

        // 4. Create the 2×2 combination grid.
        $combos = [];
        foreach ([[$small, $red, 'S-R'], [$small, $blue, 'S-B'], [$large, $red, 'L-R'], [$large, $blue, 'L-B']] as $i => [$s, $c, $sku]) {
            $combo = $this->client->products()->createVariation($productId, [
                'value_ids' => [$s, $c],
                'sku'       => $this->uid($sku),
                'price'     => 29.99 + $i,
                'stock'     => 10 * ($i + 1),
                'enabled'   => true,
            ])->getData();
            $combos[$sku] = (int)$combo['combinationid'];
            $this->assertSame($productId, (int)$combo['vcproductid']);
            $this->assertSame($setId, (int)$combo['vcoptionsetid']);
            // vcoptionids is the CSV the storefront reads for filtering.
            $this->assertStringContainsString((string)$s, (string)$combo['vcoptionids']);
            $this->assertStringContainsString((string)$c, (string)$combo['vcoptionids']);
        }
        $this->assertCount(4, $combos);

        // List variations for the product → 4 rows.
        $rows = $this->client->products()->listVariations($productId)->getData();
        $this->assertCount(4, $rows);

        // 5. Update one combination's stock.
        $upd = $this->client->products()->updateVariation($productId, $combos['L-R'], [
            'stock'    => 999,
            'sku'      => 'NEW-SKU-LR',
        ])->getData();
        $this->assertSame(999, (int)$upd['vcstock']);
        $this->assertSame('NEW-SKU-LR', $upd['vcsku']);

        // 6. Delete one combination.
        $delResp = $this->client->products()->deleteVariation($productId, $combos['S-B']);
        $this->assertSame(200, $delResp->getStatusCode());
        $this->assertTrue($delResp->getData()['deleted'] ?? false);
        $this->assertCount(3, $this->client->products()->listVariations($productId)->getData(),
            'after delete the product should have 3 of the original 4 combinations');
    }

    #[Test]
    public function create_variation_without_value_ids_returns_400(): void
    {
        $this->requireScope('products.write');
        $opt = $this->client->products()->createOption([
            'name' => 'Tmp_' . $this->uid('opt'), 'display_name' => 'Tmp',
        ])->getData();
        $set = $this->client->products()->createOptionSet([
            'name' => 'Tmp_' . $this->uid('s'), 'option_ids' => [(int)$opt['optionid']],
        ])->getData();
        $prod = $this->client->products()->create([
            'name'  => $this->uid('NoVals'),
            'sku'   => $this->uid('novals'),
            'price' => 5.00,
            'option_set_id' => (int)$set['optionsetid'],
        ])->getData();

        try {
            $this->client->products()->createVariation((int)$prod['productid'], [
                'sku'   => $this->uid('skuonly'),
                'stock' => 5,
            ]);
            $this->fail('Expected 400 when value_ids is missing');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('value_ids', $e->getErrorDetail());
        }
    }

    #[Test]
    public function create_variation_without_option_set_returns_400(): void
    {
        $this->requireScope('products.write');
        $prod = $this->client->products()->create([
            'name'  => $this->uid('NoSet'),
            'sku'   => $this->uid('noset'),
            'price' => 5.00,
        ])->getData();
        // Product has no prodoptionsetid AND we don't pass option_set_id.
        try {
            $this->client->products()->createVariation((int)$prod['productid'], [
                'value_ids' => [1],
            ]);
            $this->fail('Expected 400 when product has no option set');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('option set', strtolower($e->getErrorDetail()));
        }
    }

    #[Test]
    public function update_variation_belonging_to_different_product_returns_404(): void
    {
        $this->requireScope('products.write');

        // Two products, one option set, one variation each.
        $opt = (int)$this->client->products()->createOption(['name' => 'Iso_' . $this->uid('o'), 'display_name' => 'Iso'])->getData()['optionid'];
        $val = (int)$this->client->products()->createOptionValue($opt, ['value' => 'X'])->getData()['valueid'];
        $set = (int)$this->client->products()->createOptionSet(['name' => 'Iso_' . $this->uid('s'), 'option_ids' => [$opt]])->getData()['optionsetid'];

        $pA = (int)$this->client->products()->create([
            'name'  => $this->uid('IsoA'), 'sku' => $this->uid('isoa'),
            'price' => 1.0, 'option_set_id' => $set,
        ])->getData()['productid'];
        $pB = (int)$this->client->products()->create([
            'name'  => $this->uid('IsoB'), 'sku' => $this->uid('isob'),
            'price' => 1.0, 'option_set_id' => $set,
        ])->getData()['productid'];

        $combA = (int)$this->client->products()->createVariation($pA, ['value_ids' => [$val]])->getData()['combinationid'];

        try {
            $this->client->products()->updateVariation($pB, $combA, ['stock' => 100]);
            $this->fail('Cross-product variation update should 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function remove_option_from_set_then_list_excludes_it(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $a = (int)$this->client->products()->createOption(['name' => 'A_' . $this->uid('ro'), 'display_name' => 'A'])->getData()['optionid'];
        $b = (int)$this->client->products()->createOption(['name' => 'B_' . $this->uid('ro'), 'display_name' => 'B'])->getData()['optionid'];
        $setId = (int)$this->client->products()->createOptionSet([
            'name' => 'Two_' . $this->uid('s'), 'option_ids' => [$a, $b],
        ])->getData()['optionsetid'];

        $this->assertCount(2, $this->client->products()->getOptionSet($setId)->getData()['options']);

        $this->client->products()->removeOptionFromSet($setId, $b);

        $after = $this->client->products()->getOptionSet($setId)->getData();
        $this->assertCount(1, $after['options']);
        $this->assertSame($a, (int)$after['options'][0]['optionid']);
    }

    #[Test]
    public function create_variation_with_every_field_persists_all_of_them(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        // Minimal option + set + product so the variation has somewhere to live.
        $opt = (int)$this->client->products()->createOption(['name' => 'All_' . $this->uid('o'), 'display_name' => 'All'])->getData()['optionid'];
        $val = (int)$this->client->products()->createOptionValue($opt, ['value' => 'OnlyValue'])->getData()['valueid'];
        $set = (int)$this->client->products()->createOptionSet([
            'name' => 'AllSet_' . $this->uid('s'), 'option_ids' => [$opt],
        ])->getData()['optionsetid'];
        $pid = (int)$this->client->products()->create([
            'name'  => $this->uid('AllFieldsProd'),
            'sku'   => $this->uid('all-fields'),
            'price' => 10.00,
            'option_set_id' => $set,
        ])->getData()['productid'];

        $saleFrom = strtotime('-1 day');
        $saleTo   = strtotime('+30 days');

        $payload = [
            'value_ids'              => [$val],
            'sku'                    => 'COMB-SKU-' . $this->uid('s'),
            'mpn'                    => 'MPN-' . $this->uid('m'),
            'barcode'                => '0123456789012',
            'enabled'                => true,
            'is_default'             => true,
            // Pricing: send price/weight only. vcpricediff/vcweightdiff
            // are derived server-side, matching admin's `> 0 ? 'fixed'
            // : ''` rule. Asserted below.
            'price'                  => 49.99,
            'sale_price'             => 39.99,
            'sale_price_from'        => $saleFrom,
            'sale_price_to'          => $saleTo,
            'discard_discounts'      => true,
            'weight'                 => 0.450,
            'width'                  => 11.5,
            'height'                 => 22.5,
            'depth'                  => 33.5,
            'stock'                  => 88,
            'low_stock'              => 5,
            'allow_backorders'       => true,
            'max_backorder_quantity' => 20,
            'metadata'               => 'integration-marker',
            'customer_group_ids'     => [1, 2, 3],
            'image_url'              => $this->testImageUrl,
        ];

        $resp = $this->client->products()->createVariation($pid, $payload);
        $this->assertSame(201, $resp->getStatusCode());
        $row = $resp->getData();

        // Identity / set wiring
        $this->assertSame($pid, (int)$row['vcproductid']);
        $this->assertSame($set, (int)$row['vcoptionsetid']);
        $this->assertSame((string)$val, (string)$row['vcoptionids']);

        // Scalar columns
        $this->assertSame($payload['sku'],    $row['vcsku']);
        $this->assertSame($payload['mpn'],    $row['vcmpn']);
        $this->assertSame($payload['barcode'],$row['vcbarcode']);
        $this->assertSame(1, (int)$row['vcenabled']);
        $this->assertSame(1, (int)$row['vcisdefault']);
        $this->assertEqualsWithDelta(49.99, (float)$row['vcprice'], 0.01);
        // Server auto-derives the diff column: price > 0 → 'fixed'.
        // Partners can't (and don't need to) set add/subtract.
        $this->assertSame('fixed', $row['vcpricediff']);
        $this->assertEqualsWithDelta(39.99, (float)$row['vcsaleprice'], 0.01);
        $this->assertSame($saleFrom, (int)$row['vcsaleprice_offer_begin']);
        $this->assertSame($saleTo,   (int)$row['vcsaleprice_offer_end']);
        $this->assertSame(1, (int)$row['vcdiscarddiscounts']);
        $this->assertEqualsWithDelta(0.450, (float)$row['vcweight'], 0.001);
        $this->assertSame('fixed', $row['vcweightdiff']);
        $this->assertEqualsWithDelta(11.5, (float)$row['vcprodwidth'],  0.01);
        $this->assertEqualsWithDelta(22.5, (float)$row['vcprodheight'], 0.01);
        $this->assertEqualsWithDelta(33.5, (float)$row['vcproddepth'],  0.01);
        $this->assertSame(88, (int)$row['vcstock']);
        $this->assertSame(5,  (int)$row['vclowstock']);
        $this->assertSame(1,  (int)$row['vcallowbackorders']);
        $this->assertSame(20, (int)$row['vcmaxbackorderquantity']);
        $this->assertSame('integration-marker', $row['vcmetadata']);
        $this->assertSame('1,2,3', $row['vccustomergroupids']);

        // Image — vcimage + the three resized variants populated from
        // MEDIAMANAGER. We only assert non-empty (the exact mediafileorig
        // path depends on the storage backend) plus that image_result
        // came back successful in the envelope.
        $this->assertNotEmpty($row['vcimage'],      'vcimage must be populated from image_url');
        $this->assertNotEmpty($row['vcimagezoom'],  'vcimagezoom (resized) must be populated');
        $this->assertNotEmpty($row['vcimagestd'],   'vcimagestd (resized) must be populated');
        $this->assertNotEmpty($row['vcimagethumb'], 'vcimagethumb (resized) must be populated');
        $this->assertTrue($row['image_result']['success'] ?? false,
            'image_result envelope must report success when MEDIAMANAGER resolves the URL');
        $this->assertGreaterThan(0, (int)$row['image_result']['media_id']);
    }

    #[Test]
    public function only_one_variation_per_product_can_be_default(): void
    {
        // Storefront expects vcisdefault=1 to identify a SINGLE preselected
        // combo. The controller's clearOtherDefaults() pre-write hook must
        // demote any earlier default the moment a new one is flagged.
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $opt = (int)$this->client->products()->createOption(['name' => 'Def_' . $this->uid('o'), 'display_name' => 'Def'])->getData()['optionid'];
        $v1 = (int)$this->client->products()->createOptionValue($opt, ['value' => 'V1'])->getData()['valueid'];
        $v2 = (int)$this->client->products()->createOptionValue($opt, ['value' => 'V2'])->getData()['valueid'];
        $set = (int)$this->client->products()->createOptionSet([
            'name' => 'DefSet_' . $this->uid('s'), 'option_ids' => [$opt],
        ])->getData()['optionsetid'];
        $pid = (int)$this->client->products()->create([
            'name'  => $this->uid('DefProd'),
            'sku'   => $this->uid('def-prod'),
            'price' => 5.00,
            'option_set_id' => $set,
        ])->getData()['productid'];

        $c1 = (int)$this->client->products()->createVariation($pid, [
            'value_ids' => [$v1], 'is_default' => true,
        ])->getData()['combinationid'];
        $c2 = (int)$this->client->products()->createVariation($pid, [
            'value_ids' => [$v2], 'is_default' => true,
        ])->getData()['combinationid'];

        // After the second create, c2 should be the only default.
        $rows = $this->client->products()->listVariations($pid)->getData();
        $defaultIds = array_values(array_map(fn($r) => (int)$r['combinationid'],
            array_filter($rows, fn($r) => (int)$r['vcisdefault'] === 1)));
        $this->assertSame([$c2], $defaultIds,
            'second `is_default: true` create must demote the first — only one default allowed per product');

        // Flipping c1 back to default via UPDATE should demote c2.
        $this->client->products()->updateVariation($pid, $c1, ['is_default' => true]);
        $rows = $this->client->products()->listVariations($pid)->getData();
        $defaultIds = array_values(array_map(fn($r) => (int)$r['combinationid'],
            array_filter($rows, fn($r) => (int)$r['vcisdefault'] === 1)));
        $this->assertSame([$c1], $defaultIds,
            'updating to is_default=true must demote any earlier default');
    }

    #[Test]
    public function price_and_weight_diff_are_derived_server_side(): void
    {
        // Schema enum is ('','add','subtract','fixed') but the app only
        // reads '' / 'fixed' (admin: `$price > 0 ? 'fixed' : ''` —
        // remote.class.php:3413, remote.products.options.class.php:683).
        // The API mirrors that: partners send price / weight; the diff
        // column is derived. Sending price_diff/weight_diff directly is
        // a no-op (no friendly key exposes them) — this test pins both
        // directions of the derivation.
        $this->requireScope('products.write');
        $this->requireScope('products.read');
        $opt = (int)$this->client->products()->createOption(['name' => 'Diff_' . $this->uid('o'), 'display_name' => 'Diff'])->getData()['optionid'];
        $v1  = (int)$this->client->products()->createOptionValue($opt, ['value' => 'A'])->getData()['valueid'];
        $v2  = (int)$this->client->products()->createOptionValue($opt, ['value' => 'B'])->getData()['valueid'];
        $set = (int)$this->client->products()->createOptionSet(['name' => 'DiffSet_' . $this->uid('s'), 'option_ids' => [$opt]])->getData()['optionsetid'];
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('DiffProd'), 'sku' => $this->uid('diff-prod'),
            'price' => 1.0, 'option_set_id' => $set,
        ])->getData()['productid'];

        // With a positive price / weight → 'fixed'.
        $withPrice = $this->client->products()->createVariation($pid, [
            'value_ids' => [$v1],
            'price'     => 5.00,
            'weight'    => 0.250,
        ])->getData();
        $this->assertSame('fixed', $withPrice['vcpricediff']);
        $this->assertSame('fixed', $withPrice['vcweightdiff']);

        // Without (or zero) → ''.
        $without = $this->client->products()->createVariation($pid, [
            'value_ids' => [$v2],
            'price'     => 0,
            'weight'    => 0,
        ])->getData();
        $this->assertSame('', $without['vcpricediff']);
        $this->assertSame('', $without['vcweightdiff']);
    }

    #[Test]
    public function delete_option_set_zeroes_products_prodoptionsetid(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $opt   = (int)$this->client->products()->createOption(['name' => 'Del_' . $this->uid('o'), 'display_name' => 'Del'])->getData()['optionid'];
        $setId = (int)$this->client->products()->createOptionSet([
            'name' => 'DelSet_' . $this->uid('s'), 'option_ids' => [$opt],
        ])->getData()['optionsetid'];
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('OrphProd'), 'sku' => $this->uid('orph'),
            'price' => 1.0, 'option_set_id' => $setId,
        ])->getData()['productid'];

        $this->client->products()->deleteOptionSet($setId);

        // The product's prodoptionsetid should now be 0 (cleared by the
        // delete cascade in deleteOptionSet — without this, fetching the
        // product would dangle a pointer at a non-existent set).
        $back = $this->client->products()->get($pid)->getData();
        $this->assertSame(0, (int)$back['prodoptionsetid']);
    }
}
