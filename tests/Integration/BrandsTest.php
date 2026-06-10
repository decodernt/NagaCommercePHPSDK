<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /brands endpoints — list / search / create / update + image URL import.
 *
 * Coverage:
 *   - read: list, search
 *   - write: create (idempotent on brandname), update
 *   - URL image import: image_url + legacy brandimagefile URL form both
 *     resolve via MEDIAMANAGER and populate brandimageid + brandimagefile
 *   - validation: missing brandname rejection
 *
 * There is no GET /brands/brand/{id} route — brand reads are list / search
 * only. Roundtrip verification uses search to find the just-created row.
 *
 * No cleanup. uid()-tagged names keep re-runs distinct.
 */
final class BrandsTest extends IntegrationTestCase
{
    #[Test]
    public function list_returns_array(): void
    {
        $this->requireScope('brands.read');
        $r = $this->client->brands()->list();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    #[Test]
    public function search_returns_envelope(): void
    {
        $this->requireScope('brands.read');
        $r = $this->client->brands()->search(['limit' => 5]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertArrayHasKey('brands', $r->getData());
    }

    #[Test]
    public function create_minimal_returns_201_with_row(): void
    {
        $this->requireScope('brands.write');
        $name = $this->uid('BrandNew');
        $r = $this->client->brands()->create(['brandname' => $name]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertSame($name, $row['brandname']);
        $this->assertNotEmpty($row['brandslug'], 'slug must be auto-generated when omitted');
    }

    #[Test]
    public function create_is_idempotent_on_brandname(): void
    {
        $this->requireScope('brands.write');
        $name = $this->uid('BrandDup');

        $first = $this->client->brands()->create(['brandname' => $name]);
        $this->assertSame(201, $first->getStatusCode());
        $firstId = (int)$first->getData()['brandid'];

        // Second call with same name: 200 (NOT 201) and meta.existing.
        $second = $this->client->brands()->create(['brandname' => $name]);
        $this->assertSame(200, $second->getStatusCode(),
            're-creating with same brandname must return 200, not 201');
        $this->assertSame($firstId, (int)$second->getData()['brandid'],
            'idempotent re-create must return the same brandid');
        $this->assertTrue($second->getMeta()['existing'] ?? false,
            'meta.existing must be true on idempotent re-create');
    }

    #[Test]
    public function create_without_brandname_returns_400(): void
    {
        $this->requireScope('brands.write');
        try {
            $this->client->brands()->create(['brandslug' => $this->uid('no-name')]);
            $this->fail('Expected 400 for missing brandname');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('brandname', $e->getErrorDetail());
        }
    }

    #[Test]
    public function update_unknown_brand_returns_404(): void
    {
        $this->requireScope('brands.write');
        try {
            $this->client->brands()->update(99999999, ['brandslug' => $this->uid('ghost')]);
            $this->fail('Expected 404 for unknown brand');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function update_changes_brandslug(): void
    {
        $this->requireScope('brands.write');
        $created = $this->client->brands()->create(['brandname' => $this->uid('BrandUpd')])->getData();
        $brandId = (int)$created['brandid'];

        $newSlug = $this->uid('upd-slug');
        $r = $this->client->brands()->update($brandId, ['brandslug' => $newSlug]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame($newSlug, $r->getData()['brandslug']);
    }

    #[Test]
    public function create_with_image_url_imports_media(): void
    {
        $this->requireScope('brands.write');
        $r = $this->client->brands()->create([
            'brandname' => $this->uid('BrandImg'),
            'image_url' => $this->testImageUrl,
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertGreaterThan(0, (int)($row['brandimageid'] ?? 0),
            'image_url import should populate brandimageid; if this fails, ' .
            'MEDIAMANAGER could not reach the URL — check NC_TEST_IMAGE');
        $this->assertNotEmpty($row['brandimagefile'] ?? '',
            'brandimagefile (denormalized path) should be populated alongside brandimageid');
        $this->assertArrayHasKey('image_result', $row);
        $this->assertTrue($row['image_result']['success']);
    }

    #[Test]
    public function legacy_brandimagefile_url_form_also_imports(): void
    {
        $this->requireScope('brands.write');
        // Pre-existing partner code sends brandimagefile as a full URL
        // (instead of image_url). The controller's extractImageUrlSpec
        // sniffs http(s):// prefix and treats it as a URL spec.
        $r = $this->client->brands()->create([
            'brandname'     => $this->uid('BrandLegacy'),
            'brandimagefile' => $this->testImageUrl,
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertGreaterThan(0, (int)($row['brandimageid'] ?? 0));
        $this->assertArrayHasKey('image_result', $row);
        $this->assertTrue($row['image_result']['success']);
    }
}
