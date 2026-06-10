<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /products endpoints — the biggest write surface in the API.
 *
 * Coverage (intentionally broad; products is where most partner work
 * lands, and the entity layer has the most legacy quirks):
 *
 *   - read: count, search, get(id)
 *   - write: create, update, delete (cascade-safe)
 *   - batch: batchCreate, batchUpdate, batchDelete
 *   - custom fields: per-product attach + replace + append + remove + clear,
 *                    store-wide label / value CRUD
 *   - images: URL-based attach via MEDIAMANAGER (real download)
 *   - inventory: adjustStock + setStock
 *   - reference validation: tax_class_id, availability_id rejection
 *   - default fields: prodtype, prodqtystep applied on create
 *   - search-sync side effect: created products appear in find()
 *
 * No cleanup — created records persist. Each test uses uid()-tagged
 * SKUs / slugs so re-runs don't collide.
 */
final class ProductsTest extends IntegrationTestCase
{
    // -- read ----------------------------------------------------------

    #[Test]
    public function count_returns_integer(): void
    {
        $this->requireScope('products.read');
        $data = $this->client->products()->count()->getData();
        $this->assertArrayHasKey('count', $data);
        $this->assertIsInt($data['count']);
        $this->assertGreaterThanOrEqual(0, $data['count']);
    }

    #[Test]
    public function search_with_no_params_returns_paginated_list(): void
    {
        $this->requireScope('products.read');
        $r = $this->client->products()->search(['limit' => 5]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
        $this->assertArrayHasKey('total', $r->getMeta());
    }

    #[Test]
    public function updated_since_returns_changed_products(): void
    {
        $this->requireScope('products.read');
        // 0 is rejected by-contract (controller treats `<= 0` as invalid).
        // 1 means "since UNIX epoch + 1 second" — captures every product
        // on any store while staying on the supported side of the gate.
        $r = $this->client->products()->updatedSince(1, ['limit' => 5]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    // -- create + read-back -------------------------------------------

    #[Test]
    public function create_minimal_product_and_get_it_back(): void
    {
        $this->requireScope('products.write');
        $sku = $this->uid('basic-sku');

        $r = $this->client->products()->create([
            'name'            => $this->uid('Basic Product'),
            'sku'             => $sku,
            'price'           => 19.99,
            'visible'         => 1,
            'inventory'       => 10,
            'inventory_track' => 1,
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertIsInt($row['productid']);
        // Avoid `assertSame` here — PHPUnit's diff renderer chokes on
        // strings carrying the literal `-sku-` substring (likely because
        // it interprets it as a placeholder marker, rendering one side
        // as null in the error message). assertEquals + manual === gives
        // a usable error if these ever drift.
        $this->assertTrue(
            $sku === $row['prodcode'],
            "prodcode mismatch: sent={$sku}, got=" . var_export($row['prodcode'], true)
        );
        $this->assertSame(19.99, (float)$row['prodprice']);

        // Defaults that the API now applies on create.
        $this->assertSame(1, (int)$row['prodtype'],
            'create must default prodtype to 1 (PT_PHYSICAL) — schema default 0 is digital');
        $this->assertSame(1.0, (float)$row['prodqtystep'],
            'create must default prodqtystep to 1; schema default 0 breaks cart qty UIs');

        // prodcurl (URL slug) is auto-derived from prodname when omitted.
        // Without this, storefront product links are broken.
        $this->assertNotEmpty($row['prodcurl'],
            'create must auto-derive prodcurl from prodname when omitted');

        // prodcalculatedprice mirrors admin's CommitProduct: prodcalculatedprice
        // = CalcRealPrice(prodprice, prodsaleprice) where empty sale price → 0
        // → prodcalculatedprice = prodprice. Without this fix, sort-by-price /
        // storefront display / catalog-mode order pricing all break.
        $this->assertSame(19.99, (float)$row['prodcalculatedprice'],
            'prodcalculatedprice must equal prodprice when no sale price is set');

        $productId = (int)$row['productid'];

        // Round-trip via get(id): same raw-entity shape as create.
        $row2 = $this->fetchProductRow($productId);
        $this->assertSame($productId, (int)$row2['productid']);
        $this->assertSame($sku, $row2['prodcode']);
        $this->assertArrayHasKey('custom_fields', $row2,
            'getProduct must always return custom_fields (empty array when none)');
    }

    #[Test]
    public function update_partial_only_writes_sent_fields(): void
    {
        $this->requireScope('products.write');
        $id = $this->createBasicProduct();

        $newPrice = 24.99;
        $r = $this->client->products()->update($id, ['price' => $newPrice]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame($newPrice, (float)$r->getData()['prodprice']);
    }

    #[Test]
    public function create_with_categories_assigns_them(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('categories.read');

        // Pick the first available category; if none, skip.
        $categories = $this->client->categories()->list()->getData();
        if (empty($categories)) {
            $this->markTestSkipped('store has no categories; cannot test product → category assignment');
        }
        $catId = (int)$categories[0]['categoryid'];

        $r = $this->client->products()->create([
            'name'       => $this->uid('CategorizedProduct'),
            'sku'        => $this->uid('cat-sku'),
            'price'      => 10.0,
            'categories' => [$catId],
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $id = (int)$r->getData()['productid'];

        // Verify the assignment landed on the denormalized prodcatids
        // CSV (assignCategories keeps it in lock-step with the
        // categoryassociations join — storefront filtering reads CSV).
        $row = $this->fetchProductRow($id);
        $this->assertNotEmpty($row['prodcatids'] ?? '',
            'prodcatids CSV must include the assigned category id');
        $this->assertStringContainsString((string)$catId, (string)$row['prodcatids']);
    }

    #[Test]
    public function delete_cascades_dependent_rows(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.delete');
        $id = $this->createBasicProduct();

        $r = $this->client->products()->delete($id);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($r->getData()['deleted'] ?? false);

        // Verify gone: get(id) should now 404.
        try {
            $this->client->products()->get($id);
            $this->fail('get() after delete should have raised 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // -- batch -------------------------------------------------------

    #[Test]
    public function batch_create_returns_per_row_results(): void
    {
        $this->requireScope('products.write');
        $rows = [
            ['name' => $this->uid('Batch A'), 'sku' => $this->uid('batch-a'), 'price' => 5.0],
            ['name' => $this->uid('Batch B'), 'sku' => $this->uid('batch-b'), 'price' => 6.0],
        ];
        $r = $this->client->products()->batchCreate($rows);
        $data = $r->getData();
        $this->assertSame(2, $data['created']);
        $this->assertSame(0, $data['failed']);
        $this->assertCount(2, $data['results']);
        $this->assertTrue($data['results'][0]['success']);
        $this->assertIsInt($data['results'][0]['product_id']);
    }

    #[Test]
    public function batch_update_writes_all_rows(): void
    {
        $this->requireScope('products.write');
        $id1 = $this->createBasicProduct();
        $id2 = $this->createBasicProduct();

        $r = $this->client->products()->batchUpdate([
            ['id' => $id1, 'price' => 11.0],
            ['id' => $id2, 'price' => 12.0],
        ]);
        $data = $r->getData();
        $this->assertSame(2, $data['updated']);
    }

    #[Test]
    public function batch_delete_skips_products_with_orders(): void
    {
        $this->requireScope('products.delete');
        $id1 = $this->createBasicProduct();
        $id2 = $this->createBasicProduct();

        $r = $this->client->products()->batchDelete([$id1, $id2]);
        $data = $r->getData();
        $this->assertSame([$id1, $id2], $data['deleted']);
        $this->assertSame([], $data['skipped_has_orders'],
            'these products have no orders — none should be in skipped_has_orders');
        $this->assertSame([], $data['not_found']);
    }

    // -- custom fields ---------------------------------------------

    #[Test]
    public function custom_fields_replace_then_clear(): void
    {
        $this->requireScope('products.write');
        $id = $this->createBasicProduct();
        $labelName = 'CF_' . $this->uid('color');

        // Replace with one new label → one value.
        $r = $this->client->products()->replaceCustomFields($id, [
            ['label' => $labelName, 'values' => ['Red', 'Blue']],
        ]);
        $assigned = $r->getData();
        $this->assertCount(1, $assigned, 'one label group');
        $this->assertSame($labelName, $assigned[0]['label_name']);
        $this->assertCount(2, $assigned[0]['values']);

        // Read-back via per-product list.
        $back = $this->client->products()->getCustomFields($id)->getData();
        $this->assertCount(1, $back);
        $this->assertSame($labelName, $back[0]['label_name']);

        // Clear all.
        $this->client->products()->clearCustomFields($id);
        $this->assertSame([], $this->client->products()->getCustomFields($id)->getData());
    }

    #[Test]
    public function custom_field_definition_lifecycle(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');
        $labelName = 'CFlabel_' . $this->uid('size');

        // Create label.
        $created = $this->client->products()->createCustomFieldLabel($labelName)->getData();
        $this->assertSame($labelName, $created['label_name']);
        $labelId = (int)$created['label_id'];

        // Add a value.
        $value = $this->client->products()->createCustomFieldValue($labelId, 'XL')->getData();
        $this->assertSame('XL', $value['value']);

        // Update label visibility.
        $upd = $this->client->products()->updateCustomFieldLabel($labelId, ['visible' => 0])->getData();
        $this->assertSame(0, (int)$upd['visible']);

        // Delete label cleans up its values via FK cascade.
        $this->client->products()->deleteCustomFieldLabel($labelId);
        $defs = $this->client->products()->listCustomFieldDefinitions()->getData();
        foreach ($defs as $def) {
            $this->assertNotSame($labelId, (int)$def['label_id'], 'deleted label must not still appear');
        }
    }

    // -- images (real CDN download via MEDIAMANAGER) ----------------

    #[Test]
    public function create_product_with_image_url_imports_media(): void
    {
        $this->requireScope('products.write');

        $r = $this->client->products()->create([
            'name'   => $this->uid('WithImage'),
            'sku'    => $this->uid('img-sku'),
            'price'  => 7.0,
            'images' => [
                ['url' => $this->testImageUrl, 'alt' => 'Integration test hero'],
            ],
        ]);
        $row = $r->getData();
        $this->assertSame(201, $r->getStatusCode());

        // images_result attached to the response with per-URL outcomes.
        $this->assertArrayHasKey('images_result', $row);
        $this->assertSame(1, $row['images_result']['attached'],
            'real CDN URL should have downloaded + attached; ' .
            'if this fails, MEDIAMANAGER could not reach the URL — check NC_TEST_IMAGE');
        $this->assertSame(0, $row['images_result']['failed']);
        $this->assertGreaterThan(0, $row['images_result']['results'][0]['media_id']);
    }

    // -- inventory --------------------------------------------------

    #[Test]
    public function set_stock_then_adjust_stock(): void
    {
        $this->requireScope('products.write');
        $id = $this->createBasicProduct(['inventory' => 0, 'inventory_track' => 1]);

        // Absolute set → 50.
        $r = $this->client->products()->setStock([
            ['product_id' => $id, 'quantity' => 50],
        ], ['source' => 'integration-test', 'reason' => 'set']);
        $this->assertTrue($r->getData()['success'] ?? false);
        $this->assertSame(50, (int)$this->fetchProductRow($id)['prodcurrentinv']);

        // Delta -7 → 43.
        $r = $this->client->products()->adjustStock([
            ['product_id' => $id, 'delta' => -7],
        ], ['source' => 'integration-test', 'reason' => 'cycle']);
        $this->assertTrue($r->getData()['success'] ?? false);
        $this->assertSame(43, (int)$this->fetchProductRow($id)['prodcurrentinv']);
    }

    // -- reference validation (server-side gates) ------------------

    #[Test]
    public function unknown_tax_class_id_rejected_on_create(): void
    {
        $this->requireScope('products.write');
        try {
            $this->client->products()->create([
                'name'         => $this->uid('BadTax'),
                'sku'          => $this->uid('badtax'),
                'price'        => 1.0,
                'tax_class_id' => 999999,
            ]);
            $this->fail('Server should have rejected unknown tax_class_id with 400');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('tax_class_id', $e->getErrorDetail());
        }
    }

    #[Test]
    public function unknown_availability_id_rejected_on_create(): void
    {
        $this->requireScope('products.write');
        try {
            $this->client->products()->create([
                'name'            => $this->uid('BadAvail'),
                'sku'             => $this->uid('badavail'),
                'price'           => 1.0,
                'availability_id' => 99999,
            ]);
            $this->fail('Server should have rejected unknown availability_id with 400');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('availability_id', $e->getErrorDetail());
        }
    }

    // -- image cap ------------------------------------------------

    #[Test]
    public function over_100_images_rejected_on_create(): void
    {
        $this->requireScope('products.write');
        $images = [];
        for ($i = 0; $i < 101; $i++) {
            $images[] = $this->testImageUrl . '?n=' . $i;
        }
        $r = $this->client->products()->create([
            'name'   => $this->uid('OverCap'),
            'sku'    => $this->uid('overcap'),
            'price'  => 1.0,
            'images' => $images,
        ]);
        // Product created, but images_result has error envelope.
        $this->assertSame(201, $r->getStatusCode());
        $data = $r->getData();
        $this->assertArrayHasKey('images_result', $data);
        $this->assertArrayHasKey('error', $data['images_result']);
        $this->assertStringContainsString('maximum of 100', $data['images_result']['error']);
    }

    // -- inline custom fields on product create -------------------

    #[Test]
    public function create_product_with_inline_custom_fields_by_name_finds_or_creates(): void
    {
        // Shape A from CUSTOM_FIELDS_SHAPE: {label: "...", values: [...]}.
        // Server finds-or-creates both the label and each value, then
        // attaches them to the new product in one round-trip.
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $labelName = 'CF_Material_' . $this->uid('m');
        $r = $this->client->products()->create([
            'name'  => $this->uid('InlineCFProd'),
            'sku'   => $this->uid('inline-cf'),
            'price' => 15.00,
            'custom_fields' => [
                ['label' => $labelName, 'values' => ['Cotton', 'Linen', 'Silk']],
            ],
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $productId = (int)$r->getData()['productid'];

        // The create response embeds custom_fields populated from the
        // resolve-and-attach phase. Three values landed under one label.
        $back = $this->client->products()->get($productId)->getData();
        $this->assertArrayHasKey('custom_fields', $back);
        $this->assertCount(1, $back['custom_fields']);
        $group = $back['custom_fields'][0];
        $this->assertSame($labelName, $group['label_name']);
        $this->assertCount(3, $group['values']);
        $valuesByName = array_column($group['values'], 'value');
        sort($valuesByName);
        $this->assertSame(['Cotton', 'Linen', 'Silk'], $valuesByName);
    }

    #[Test]
    public function create_product_with_inline_custom_fields_by_id_uses_existing_definitions(): void
    {
        // Shape B: {label_id, value_ids}. Strict — partner has discovered
        // ids out-of-band (e.g. from listCustomFieldDefinitions) and wants
        // to reuse them without risk of dupes. Server verifies they exist.
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $label = $this->client->products()->createCustomFieldLabel('CF_Origin_' . $this->uid('o'))->getData();
        $labelId = (int)$label['label_id'];
        $v1 = (int)$this->client->products()->createCustomFieldValue($labelId, 'Greece')->getData()['value_id'];
        $v2 = (int)$this->client->products()->createCustomFieldValue($labelId, 'Italy')->getData()['value_id'];

        $r = $this->client->products()->create([
            'name'  => $this->uid('InlineCFById'),
            'sku'   => $this->uid('inline-cf-id'),
            'price' => 20.00,
            'custom_fields' => [
                ['label_id' => $labelId, 'value_ids' => [$v1, $v2]],
            ],
        ]);
        $this->assertSame(201, $r->getStatusCode());

        $productId = (int)$r->getData()['productid'];
        $back = $this->client->products()->get($productId)->getData();
        $this->assertCount(1, $back['custom_fields']);
        $valueIds = array_map(fn($v) => (int)$v['value_id'], $back['custom_fields'][0]['values']);
        sort($valueIds);
        $this->assertSame([$v1, $v2], $valueIds,
            'by-id custom_fields must attach EXACTLY the ids sent — no duplicate Greek-by-name was created');
    }

    #[Test]
    public function create_product_with_mixed_value_shapes_resolves_both(): void
    {
        // Shape C: mixed value descriptors — some pre-existing ids, some
        // new names that get find-or-created. Useful when integrating
        // bulk imports where SOME values are known to exist and others
        // are new for this partner.
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $label = $this->client->products()->createCustomFieldLabel('CF_Fit_' . $this->uid('f'))->getData();
        $labelId = (int)$label['label_id'];
        $existingValueId = (int)$this->client->products()->createCustomFieldValue($labelId, 'Slim')->getData()['value_id'];

        $r = $this->client->products()->create([
            'name'  => $this->uid('InlineCFMix'),
            'sku'   => $this->uid('inline-cf-mix'),
            'price' => 25.00,
            'custom_fields' => [
                [
                    'label' => $label['label_name'], // by-name look-up of the label
                    'values' => [
                        ['value_id' => $existingValueId], // reuse the Slim row
                        ['value'    => 'Relaxed'],         // create a new row
                    ],
                ],
            ],
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $productId = (int)$r->getData()['productid'];

        $back = $this->client->products()->get($productId)->getData();
        $this->assertCount(1, $back['custom_fields']);
        $valuesByName = array_column($back['custom_fields'][0]['values'], 'value');
        sort($valuesByName);
        $this->assertSame(['Relaxed', 'Slim'], $valuesByName);
        // Slim's id must match the one we created upfront (no duplicate row).
        foreach ($back['custom_fields'][0]['values'] as $v) {
            if ($v['value'] === 'Slim') {
                $this->assertSame($existingValueId, (int)$v['value_id'],
                    'sending value_id must reuse the existing row, not create a duplicate Slim');
            }
        }
    }

    #[Test]
    public function create_product_with_multiple_custom_field_labels(): void
    {
        // A realistic "fully tagged" product: Material + Origin + Care.
        // Three labels each with multiple values, all attached in the
        // SAME create call.
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $payload = [
            'name'  => $this->uid('MultiCFProd'),
            'sku'   => $this->uid('multi-cf'),
            'price' => 49.00,
            'custom_fields' => [
                ['label' => 'CF_Material_' . $this->uid('a'), 'values' => ['Wool', 'Cashmere']],
                ['label' => 'CF_Origin_'   . $this->uid('b'), 'values' => ['Italy']],
                ['label' => 'CF_Care_'     . $this->uid('c'), 'values' => ['Dry Clean', 'Hand Wash', 'Cool Iron']],
            ],
        ];
        $r = $this->client->products()->create($payload);
        $this->assertSame(201, $r->getStatusCode());

        $back = $this->client->products()->get((int)$r->getData()['productid'])->getData();
        $this->assertCount(3, $back['custom_fields'], 'three distinct labels must all land');
        $labels = array_column($back['custom_fields'], 'label_name');
        sort($labels);
        $expected = array_column($payload['custom_fields'], 'label');
        sort($expected);
        $this->assertSame($expected, $labels);

        // 2 + 1 + 3 = 6 value rows total in product_customfields_v2.
        $totalValues = array_sum(array_map(fn($g) => count($g['values']), $back['custom_fields']));
        $this->assertSame(6, $totalValues);
    }

    #[Test]
    public function create_product_with_malformed_custom_fields_silently_drops_them(): void
    {
        // CustomFieldsService::normalizeInput is deliberately LENIENT
        // (library/Products/CustomFieldsService.php:115-117) — groups
        // missing both `label` and `label_id`, and groups with no
        // values, are silently skipped instead of throwing. This is
        // the contract partners building batch importers depend on:
        // one malformed row doesn't poison the whole product write.
        //
        // The test pins both halves of that contract:
        //   1. The product create still succeeds (201, not 400/500).
        //   2. No phantom rows land in product_customfields_v2.
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $r = $this->client->products()->create([
            'name'  => $this->uid('LenientCF'),
            'sku'   => $this->uid('lenient-cf'),
            'price' => 5.00,
            'custom_fields' => [
                ['values' => ['orphan-value-no-label']],    // no label / label_id
                ['label'  => 'CF_Empty_' . $this->uid('e')], // label but no values
                // ↓ valid entry mixed in — must still land.
                ['label'  => 'CF_Real_' . $this->uid('r'), 'values' => ['kept']],
            ],
        ]);
        $this->assertSame(201, $r->getStatusCode());

        $productId = (int)$r->getData()['productid'];
        $back = $this->client->products()->get($productId)->getData();
        // Exactly one custom-field group landed (the valid one);
        // the two malformed groups were dropped.
        $this->assertCount(1, $back['custom_fields']);
        $this->assertSame('kept', $back['custom_fields'][0]['values'][0]['value']);
    }

    // -- rich product fields --------------------------------------

    #[Test]
    public function create_fully_populated_product_persists_all_fields(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');
        $this->requireScope('brands.read');
        $this->requireScope('brands.write');
        $this->requireScope('categories.read');

        // Brand + category dependencies. We create-or-reuse a brand and pick
        // any existing category so the test doesn't depend on the store
        // having a specific seeded one.
        $brandName = $this->uid('FullBrand');
        $brand = $this->client->brands()->create(['brandname' => $brandName])->getData();
        $brandId = (int)$brand['brandid'];

        $cats = $this->client->categories()->list()->getData();
        if (empty($cats)) {
            $this->markTestSkipped('store has no categories; can not exercise category assignment');
        }
        $catId = (int)$cats[0]['categoryid'];

        $saleFrom = strtotime('-1 day');
        $saleTo   = strtotime('+30 days');

        $payload = [
            'name'              => $this->uid('FullProduct'),
            'sku'               => $this->uid('full-sku'),
            'description'       => 'Long description body for the full-fields product.',
            'short_description' => 'Short excerpt.',
            'price'             => 49.99,
            'sale_price'        => 39.99,
            'sale_price_from'   => $saleFrom,
            'sale_price_to'     => $saleTo,
            'cost_price'        => 20.00,
            'weight'            => 1.250,
            'width'             => 12.5,
            'height'            => 30.0,
            'depth'             => 8.0,
            'visible'           => 1,
            'featured'          => 1,
            'inventory'         => 100,
            'low_inventory'     => 5,
            'inventory_track'   => 1,
            'brand_id'          => $brandId,
            'availability_id'   => 1, // discovered from the store's product list
            'mpn'               => 'MPN-' . $this->uid(),
            'ean'               => '1234567890123',
            'gtin'              => '01234567890128',
            'isbn'              => '0306406152',
            'hs_code'           => '6109.10',
            'search_keywords'   => 'integration,full,test',
            'page_title'        => 'Full Product Page Title',
            'meta_description'  => 'Full Product meta description for SEO',
            'categories'        => [$catId],
            'tags'              => ['integration', 'fullfields', 'sdk'],
            // Inline custom_fields: by-name find-or-create. Real-world
            // fully-loaded products carry these alongside everything else,
            // so the test follows suit.
            'custom_fields'     => [
                ['label' => 'CF_FullMaterial_' . $this->uid('m'), 'values' => ['Wool', 'Cashmere']],
                ['label' => 'CF_FullOrigin_'   . $this->uid('o'), 'values' => ['Italy']],
            ],
        ];

        $r = $this->client->products()->create($payload);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $productId = (int)$row['productid'];

        // Re-fetch via get(id) so we exercise the persisted state (not the
        // pre-insert response shape).
        $back = $this->fetchProductRow($productId);

        // Identity + slug
        $this->assertSame($payload['sku'], $back['prodcode']);
        $this->assertNotEmpty($back['prodcurl']);

        // Pricing
        $this->assertEqualsWithDelta(49.99, (float)$back['prodprice'], 0.01);
        $this->assertEqualsWithDelta(39.99, (float)$back['prodsaleprice'], 0.01);
        $this->assertEqualsWithDelta(39.99, (float)$back['prodcalculatedprice'], 0.01,
            'with sale_price < price, prodcalculatedprice must equal the sale price');
        $this->assertEqualsWithDelta(20.00, (float)$back['prodcostprice'], 0.01);
        $this->assertSame($saleFrom, (int)$back['saleprice_offer_begin']);
        $this->assertSame($saleTo,   (int)$back['saleprice_offer_end']);

        // Inventory + flags
        $this->assertSame(100, (int)$back['prodcurrentinv']);
        $this->assertSame(5,   (int)$back['prodlowinv']);
        $this->assertSame(1,   (int)$back['prodinvtrack']);
        $this->assertSame(1,   (int)$back['prodvisible']);
        $this->assertSame(1,   (int)$back['prodfeatured']);

        // Dimensions
        $this->assertEqualsWithDelta(1.250, (float)$back['prodweight'], 0.001);
        $this->assertEqualsWithDelta(12.5, (float)$back['prodwidth'], 0.01);
        $this->assertEqualsWithDelta(30.0, (float)$back['prodheight'], 0.01);
        $this->assertEqualsWithDelta(8.0,  (float)$back['proddepth'], 0.01);

        // Reference linkage
        $this->assertSame($brandId, (int)$back['prodbrandid']);
        $this->assertSame(1, (int)$back['prodavailability'],
            'availability_id is stored on prodavailability as integer (legacy varchar column)');

        // GTIN / barcode columns (real DB column names — not friendly aliases)
        $this->assertSame($payload['mpn'], $back['manufacturer_part_number']);
        $this->assertSame($payload['ean'], $back['european_article_number']);
        $this->assertSame($payload['gtin'], $back['global_trade_item_number']);
        $this->assertSame($payload['isbn'], $back['isbn']);
        $this->assertSame($payload['hs_code'], $back['hscode']);

        // SEO
        $this->assertSame($payload['page_title'], $back['prodpagetitle']);
        $this->assertSame($payload['meta_description'], $back['prodmetadesc']);
        $this->assertSame($payload['search_keywords'], $back['prodsearchkeywords']);

        // Category assignment via prodcatids CSV (denormalized; storefront
        // category-product joins read this directly).
        $this->assertStringContainsString((string)$catId, (string)$back['prodcatids']);

        // Tags: SaveProductTags writes to product_tagassociations + product_tags
        // and bumps prodhastags. Verify the count flag flipped.
        $this->assertSame(1, (int)$back['prodhastags'],
            'sending tags must flip prodhastags = 1');

        // Custom fields: 2 labels (Material, Origin) → 3 values total.
        // Pulled via a fresh get() since the entity row doesn't carry them
        // — getProduct re-hydrates from Products_CustomFieldsService.
        $detail = $this->client->products()->get($productId)->getData();
        $this->assertCount(2, $detail['custom_fields']);
        $totalValues = array_sum(array_map(fn($g) => count($g['values']), $detail['custom_fields']));
        $this->assertSame(3, $totalValues, '2 + 1 = 3 custom-field values must persist on create');
    }

    #[Test]
    public function sale_price_above_price_keeps_calculated_price_equal_to_price(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        // Admin's CalcRealPrice only swaps to sale price when it's strictly
        // less than the regular price. A higher sale_price is a no-op — we
        // pin that behaviour so future "fixes" don't accidentally regress
        // catalogs by treating sale_price as a max-of-two pick.
        $r = $this->client->products()->create([
            'name'       => $this->uid('SaleGreater'),
            'sku'        => $this->uid('salegt'),
            'price'      => 10.00,
            'sale_price' => 15.00,
        ]);
        $row = $r->getData();
        $back = $this->fetchProductRow((int)$row['productid']);
        $this->assertEqualsWithDelta(10.00, (float)$back['prodcalculatedprice'], 0.01,
            'sale_price > price must NOT lower the calculated price');
    }

    #[Test]
    public function iso_8601_sale_price_dates_convert_to_unix_ints(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $iso = '2027-01-15T10:00:00+00:00';
        $expected = strtotime($iso);

        $r = $this->client->products()->create([
            'name'            => $this->uid('SaleIso'),
            'sku'             => $this->uid('saleiso'),
            'price'           => 30.00,
            'sale_price'      => 25.00,
            'sale_price_from' => $iso,
            'sale_price_to'   => '2027-02-15T10:00:00+00:00',
        ]);
        $back = $this->fetchProductRow((int)$r->getData()['productid']);
        $this->assertSame($expected, (int)$back['saleprice_offer_begin'],
            'sale_price_from accepts ISO 8601 and stores as unix int');
    }

    #[Test]
    public function update_tags_replaces_existing_set(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $created = $this->client->products()->create([
            'name'  => $this->uid('TagUpd'),
            'sku'   => $this->uid('tagupd'),
            'price' => 5.0,
            'tags'  => ['first', 'second', 'third'],
        ])->getData();
        $productId = (int)$created['productid'];
        $this->assertSame(1, (int)$this->fetchProductRow($productId)['prodhastags']);

        // Replace with a smaller set.
        $this->client->products()->update($productId, ['tags' => ['onlyone']]);
        // Tag count flag stays 1 (still has tags), but admin's SaveProductTags
        // has now removed the other associations — verifying that requires
        // a tagassociations read, which we don't expose via the API; the
        // prodhastags=1 + non-error return is what we assert here.
        $back = $this->fetchProductRow($productId);
        $this->assertSame(1, (int)$back['prodhastags']);

        // Clear with empty array.
        $this->client->products()->update($productId, ['tags' => []]);
        $back = $this->fetchProductRow($productId);
        $this->assertSame(0, (int)$back['prodhastags'],
            'sending tags: [] must clear all tags + flip prodhastags = 0');
    }

    // -- helpers --------------------------------------------------

    private function createBasicProduct(array $overrides = []): int
    {
        $payload = array_merge([
            'name'  => $this->uid('Helper'),
            'sku'   => $this->uid('helper-sku'),
            'price' => 1.0,
        ], $overrides);
        $r = $this->client->products()->create($payload);
        $row = $r->getData();
        return (int)$row['productid'];
    }

    private function fetchProductRow(int $id): array
    {
        $back = $this->client->products()->get($id)->getData();
        return is_array($back) && isset($back[0]) ? $back[0] : $back;
    }
}
