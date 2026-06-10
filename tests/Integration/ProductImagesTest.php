<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Per-product image gallery — single-row CRUD.
 *
 * Pins:
 *   - addImage appends without disturbing existing rows
 *   - is_thumbnail=true on add demotes any prior thumbnail
 *   - updateImage promotes/demotes thumbnail with same single-thumb invariant
 *   - deleteImage of a thumbnail auto-promotes the next image in sort order
 *   - cross-product access (image belongs to product B) returns 404
 *
 * Uses NC_TEST_IMAGE (with cachebust query strings) so each call yields a
 * distinct media row — otherwise MEDIAMANAGER's dedupe folds repeat URLs
 * onto a single media id.
 */
final class ProductImagesTest extends IntegrationTestCase
{
    private function distinctImageUrl(string $tag): string
    {
        return $this->testImageUrl . '?cb=' . $tag . '-' . bin2hex(random_bytes(3));
    }

    #[Test]
    public function add_image_appends_without_disturbing_existing(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        // Start with a product carrying ONE thumbnail image.
        $created = $this->client->products()->create([
            'name'   => $this->uid('ImgAppend'),
            'sku'    => $this->uid('img-app'),
            'price'  => 5.0,
            'images' => [['url' => $this->distinctImageUrl('first')]],
        ])->getData();
        $productId = (int)$created['productid'];
        $before = $this->client->products()->listImages($productId)->getData();
        $this->assertCount(1, $before);
        $firstId = (int)$before[0]['imageid'];

        // Append a second image WITHOUT is_thumbnail. The existing
        // thumb must survive.
        $r = $this->client->products()->addImage($productId, [
            'url' => $this->distinctImageUrl('second'),
            'alt' => 'second shot',
        ]);
        $this->assertSame(201, $r->getStatusCode());

        $after = $this->client->products()->listImages($productId)->getData();
        $this->assertCount(2, $after, 'append must not delete the existing row');
        $thumbs = array_filter($after, fn($r) => (int)$r['imageisthumb'] === 1);
        $this->assertCount(1, $thumbs);
        $this->assertSame($firstId, (int)array_values($thumbs)[0]['imageid'],
            'original thumbnail must still be the thumbnail after a non-thumb append');
    }

    #[Test]
    public function add_image_with_thumbnail_demotes_prior(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $created = $this->client->products()->create([
            'name'   => $this->uid('ImgDemote'),
            'sku'    => $this->uid('img-dem'),
            'price'  => 5.0,
            'images' => [['url' => $this->distinctImageUrl('first')]],
        ])->getData();
        $productId = (int)$created['productid'];
        $firstId   = (int)$this->client->products()->listImages($productId)->getData()[0]['imageid'];

        $newImg = $this->client->products()->addImage($productId, [
            'url'          => $this->distinctImageUrl('second'),
            'is_thumbnail' => true,
        ])->getData();
        $this->assertSame(1, (int)$newImg['imageisthumb']);

        // First row must be demoted.
        $all = $this->client->products()->listImages($productId)->getData();
        $thumbs = array_filter($all, fn($r) => (int)$r['imageisthumb'] === 1);
        $this->assertCount(1, $thumbs,
            'only one thumbnail per product allowed; promoting on append must demote the prior');
        $this->assertNotSame($firstId, (int)array_values($thumbs)[0]['imageid']);
    }

    #[Test]
    public function update_image_promotes_to_thumbnail(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $created = $this->client->products()->create([
            'name'   => $this->uid('ImgPromote'),
            'sku'    => $this->uid('img-pro'),
            'price'  => 5.0,
            'images' => [
                ['url' => $this->distinctImageUrl('a')],
                ['url' => $this->distinctImageUrl('b')],
            ],
        ])->getData();
        $productId = (int)$created['productid'];
        $rows = $this->client->products()->listImages($productId)->getData();
        $this->assertCount(2, $rows);

        // First row is the auto-promoted thumb; second is not.
        $thumbId    = (int)array_values(array_filter($rows, fn($r) => (int)$r['imageisthumb'] === 1))[0]['imageid'];
        $nonThumbId = (int)array_values(array_filter($rows, fn($r) => (int)$r['imageisthumb'] === 0))[0]['imageid'];

        // Promote the non-thumb via update.
        $upd = $this->client->products()->updateImage($productId, $nonThumbId, ['is_thumbnail' => true])->getData();
        $this->assertSame(1, (int)$upd['imageisthumb']);

        // Old thumb must be demoted.
        $back = $this->client->products()->listImages($productId)->getData();
        $stillThumb = array_filter($back, fn($r) => (int)$r['imageisthumb'] === 1);
        $this->assertCount(1, $stillThumb);
        $this->assertSame($nonThumbId, (int)array_values($stillThumb)[0]['imageid']);
    }

    #[Test]
    public function update_image_changes_sort_and_alt(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $created = $this->client->products()->create([
            'name'   => $this->uid('ImgPatch'),
            'sku'    => $this->uid('img-patch'),
            'price'  => 5.0,
            'images' => [['url' => $this->distinctImageUrl('p')]],
        ])->getData();
        $productId = (int)$created['productid'];
        $imageId   = (int)$this->client->products()->listImages($productId)->getData()[0]['imageid'];

        $r = $this->client->products()->updateImage($productId, $imageId, [
            'sort_order' => 42,
            'alt'        => 'edited alt text',
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $back = $r->getData();
        $this->assertSame(42, (int)$back['imagesort']);
        $this->assertSame('edited alt text', $back['imagedesc']);
    }

    #[Test]
    public function delete_image_auto_promotes_next_thumbnail(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $created = $this->client->products()->create([
            'name'   => $this->uid('ImgDel'),
            'sku'    => $this->uid('img-del'),
            'price'  => 5.0,
            'images' => [
                ['url' => $this->distinctImageUrl('a')],
                ['url' => $this->distinctImageUrl('b')],
                ['url' => $this->distinctImageUrl('c')],
            ],
        ])->getData();
        $productId = (int)$created['productid'];
        $rows = $this->client->products()->listImages($productId)->getData();
        $thumb = array_values(array_filter($rows, fn($r) => (int)$r['imageisthumb'] === 1))[0];
        $thumbId = (int)$thumb['imageid'];

        $r = $this->client->products()->deleteImage($productId, $thumbId);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($r->getData()['deleted']);
        $this->assertTrue($r->getData()['thumbnail_promoted'],
            'thumbnail_promoted must report true when the deleted row was the thumb');

        $after = $this->client->products()->listImages($productId)->getData();
        $this->assertCount(2, $after);
        $newThumbs = array_filter($after, fn($r) => (int)$r['imageisthumb'] === 1);
        $this->assertCount(1, $newThumbs,
            'after deleting the thumbnail another image must auto-promote so the product still has a thumb');
    }

    #[Test]
    public function delete_image_belonging_to_different_product_returns_404(): void
    {
        $this->requireScope('products.write');

        $pA = (int)$this->client->products()->create([
            'name' => $this->uid('PA'), 'sku' => $this->uid('pa'),
            'price' => 1.0,
            'images' => [['url' => $this->distinctImageUrl('a')]],
        ])->getData()['productid'];
        $pB = (int)$this->client->products()->create([
            'name' => $this->uid('PB'), 'sku' => $this->uid('pb'),
            'price' => 1.0,
        ])->getData()['productid'];

        $imageId = (int)$this->client->products()->listImages($pA)->getData()[0]['imageid'];

        try {
            $this->client->products()->deleteImage($pB, $imageId);
            $this->fail('cross-product image delete should 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function add_image_without_url_returns_400(): void
    {
        $this->requireScope('products.write');
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('NoUrl'), 'sku' => $this->uid('no-url'),
            'price' => 1.0,
        ])->getData()['productid'];

        try {
            $this->client->products()->addImage($pid, ['alt' => 'no url']);
            $this->fail('Expected 400 for missing url');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }
}
