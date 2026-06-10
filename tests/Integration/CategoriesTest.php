<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /categories endpoints — tree write surface + URL image import.
 *
 * Coverage:
 *   - read: list, search, get(id)
 *   - write: create (single), update, batchCreate (tree)
 *   - parent linkage: batchCreate parent_ref dependency resolution
 *   - image: image_url → MEDIAMANAGER fetch + catimageid wiring
 *   - validation: missing catname, batch duplicate refs, dangling parent_ref
 *
 * No cleanup. Each test uses uid()-tagged names / slugs to avoid collisions
 * across runs.
 */
final class CategoriesTest extends IntegrationTestCase
{
    #[Test]
    public function list_returns_array_of_categories(): void
    {
        $this->requireScope('categories.read');
        $r = $this->client->categories()->list();
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertIsArray($data);
        // Each row carries the join-projection: at minimum categoryid + catname
        // and the computed url field that listCategories() always populates.
        if (!empty($data)) {
            $this->assertArrayHasKey('categoryid', $data[0]);
            $this->assertArrayHasKey('catname', $data[0]);
            $this->assertArrayHasKey('url', $data[0]);
        }
    }

    #[Test]
    public function search_with_query_filters_results(): void
    {
        $this->requireScope('categories.read');
        // Empty search should still return the paginated envelope.
        $r = $this->client->categories()->search(['limit' => 5]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertArrayHasKey('categories', $r->getData());
    }

    #[Test]
    public function create_minimal_then_get_roundtrip(): void
    {
        $this->requireScope('categories.write');
        $this->requireScope('categories.read');

        $name = $this->uid('CatRoundtrip');
        $r = $this->client->categories()->create(['catname' => $name]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertIsInt((int)$row['categoryid']);
        $this->assertSame($name, $row['catname']);
        // Server auto-generates catcurl from catname when omitted.
        $this->assertNotEmpty($row['catcurl'], 'catcurl must be auto-generated from catname');

        $catId = (int)$row['categoryid'];

        $back = $this->client->categories()->get($catId)->getData();
        $this->assertSame($catId, (int)$back['categoryid']);
        $this->assertSame($name, $back['catname']);
    }

    #[Test]
    public function create_without_catname_returns_400(): void
    {
        $this->requireScope('categories.write');
        try {
            $this->client->categories()->create(['catdesc' => $this->uid('no-name')]);
            $this->fail('Expected 400 for missing catname');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('catname', $e->getErrorDetail());
        }
    }

    #[Test]
    public function update_changes_visible_field(): void
    {
        $this->requireScope('categories.write');
        $name = $this->uid('CatUpd');
        $created = $this->client->categories()->create(['catname' => $name])->getData();
        $catId = (int)$created['categoryid'];

        // Flip catvisible to 0.
        $r = $this->client->categories()->update($catId, ['catvisible' => 0]);
        $this->assertSame(200, $r->getStatusCode());

        // Read back through get(id) to confirm the field persisted (not just
        // accepted by the writer).
        $back = $this->client->categories()->get($catId)->getData();
        $this->assertSame(0, (int)$back['catvisible']);
    }

    #[Test]
    public function batch_create_resolves_parent_refs_in_topological_order(): void
    {
        $this->requireScope('categories.write');

        // Two-level tree: ROOT → CHILD. Send CHILD first so the topological
        // sort has to reorder them. ROOT comes second to verify ordering
        // actually happens at the server, not by accident.
        $rootRef = 'root_' . $this->uid('btchcat');
        $childRef = 'child_' . $this->uid('btchcat');

        $r = $this->client->categories()->batchCreate([
            ['ref' => $childRef, 'parent_ref' => $rootRef, 'catname' => $this->uid('BatchChild')],
            ['ref' => $rootRef, 'catname' => $this->uid('BatchRoot')],
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertSame(2, $data['created']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(2, $data['total']);

        // Find the resolved ids for ref→id mapping.
        $rootId = null;
        $childId = null;
        foreach ($data['results'] as $row) {
            if (($row['ref'] ?? '') === $rootRef)  { $rootId = (int)$row['category_id']; }
            if (($row['ref'] ?? '') === $childRef) { $childId = (int)$row['category_id']; }
        }
        $this->assertNotNull($rootId);
        $this->assertNotNull($childId);

        // Verify the child landed under the correct parent.
        $childRow = $this->client->categories()->get($childId)->getData();
        $this->assertSame($rootId, (int)$childRow['catparentid'],
            'child must be wired under the batch-local root via parent_ref resolution');
    }

    #[Test]
    public function batch_create_rejects_duplicate_refs(): void
    {
        $this->requireScope('categories.write');
        try {
            $this->client->categories()->batchCreate([
                ['ref' => 'dup', 'catname' => $this->uid('A')],
                ['ref' => 'dup', 'catname' => $this->uid('B')],
            ]);
            $this->fail('Server should reject duplicate refs in batch with 400');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('Duplicate ref', $e->getErrorDetail());
        }
    }

    #[Test]
    public function batch_create_rejects_dangling_parent_ref(): void
    {
        $this->requireScope('categories.write');
        try {
            $this->client->categories()->batchCreate([
                ['parent_ref' => 'ghost', 'catname' => $this->uid('orphan')],
            ]);
            $this->fail('Server should reject dangling parent_ref with 400');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('parent_ref', $e->getErrorDetail());
        }
    }

    #[Test]
    public function create_subcategory_via_catparentid(): void
    {
        $this->requireScope('categories.write');
        $this->requireScope('categories.read');

        // Single-shot subcategory creation: pass catparentid pointing at an
        // existing category. (batch_create_resolves_parent_refs above
        // exercises the same wiring via parent_ref; this test pins the
        // simpler single-create path partners are likely to use first.)
        $parent = $this->client->categories()->create([
            'catname' => $this->uid('ParentCat'),
        ])->getData();
        $parentId = (int)$parent['categoryid'];

        $childName = $this->uid('SubCat');
        $r = $this->client->categories()->create([
            'catname'     => $childName,
            'catparentid' => $parentId,
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $child = $r->getData();
        $this->assertSame($parentId, (int)$child['catparentid'],
            'subcategory must point at the parent via catparentid');
        $childId = (int)$child['categoryid'];

        // The nested-set tree rebuild kicks on every create; child's
        // catnsetleft should fall inside the parent's [left, right] window.
        $parentAfter = $this->client->categories()->get($parentId)->getData();
        $childAfter  = $this->client->categories()->get($childId)->getData();
        $this->assertGreaterThan((int)$parentAfter['catnsetleft'], (int)$childAfter['catnsetleft']);
        $this->assertLessThan((int)$parentAfter['catnsetright'], (int)$childAfter['catnsetright']);
    }

    #[Test]
    public function create_with_image_url_attaches_media(): void
    {
        $this->requireScope('categories.write');
        $r = $this->client->categories()->create([
            'catname'   => $this->uid('CatWithImage'),
            'image_url' => $this->testImageUrl,
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        // After image_url import succeeds, catimageid is populated.
        $this->assertGreaterThan(0, (int)($row['catimageid'] ?? 0),
            'image_url import should populate catimageid; if this fails, ' .
            'MEDIAMANAGER could not reach the URL — check NC_TEST_IMAGE');
    }
}
