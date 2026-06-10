<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * /media/upload — standalone media library upload by URL.
 *
 * Covers the three input shapes the SDK's buildBody() normalizes:
 *  - bare string URL
 *  - array of bare URLs
 *  - array of {url, alt} objects
 *
 * Dedupe happens by remote URL + content hash, so re-uploading the same
 * URL across runs returns the same media_id without re-downloading.
 */
final class MediaTest extends IntegrationTestCase
{
    #[Test]
    public function single_url_string_uploads_and_returns_media_id(): void
    {
        $this->requireScope('media.write');
        $r = $this->client->media()->uploadByUrl($this->testImageUrl);
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertSame(1, $data['uploaded']);
        $this->assertSame(0, $data['failed']);
        $this->assertCount(1, $data['results']);
        $this->assertGreaterThan(0, (int)$data['results'][0]['media_id']);
    }

    #[Test]
    public function array_of_url_strings_uploads_all(): void
    {
        $this->requireScope('media.write');
        $r = $this->client->media()->uploadByUrl([
            $this->testImageUrl,
            $this->testImageUrl . '?cb=' . uniqid(),
        ]);
        $this->assertSame(200, $r->getStatusCode());
        // First URL dedupes against earlier test; second is a fresh cachebust
        // — both should report success regardless of dedupe state.
        $this->assertSame(0, $r->getData()['failed']);
    }

    #[Test]
    public function array_of_objects_with_alt_uploads_with_metadata(): void
    {
        $this->requireScope('media.write');
        $r = $this->client->media()->uploadByUrl([
            ['url' => $this->testImageUrl, 'alt' => 'integration test alt'],
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame(0, $r->getData()['failed']);
    }

    #[Test]
    public function broken_url_records_per_url_error_without_killing_batch(): void
    {
        $this->requireScope('media.write');
        $r = $this->client->media()->uploadByUrl([
            $this->testImageUrl,
            'https://nagacommerce.invalid/this-host-does-not-resolve.png',
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        // Real URL succeeds; broken one fails — partial-success model.
        $this->assertGreaterThanOrEqual(1, $data['uploaded']);
        $this->assertGreaterThanOrEqual(1, $data['failed']);
    }
}
