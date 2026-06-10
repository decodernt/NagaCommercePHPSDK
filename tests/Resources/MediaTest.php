<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Media;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the standalone Media SDK resource.
 *
 * The resource accepts three convenience argument shapes — single string,
 * list of strings, list of objects — and maps each to the wire format
 * the server expects (`url`, `urls`, or `images`). buildBody() is the
 * pure mapping; we test it directly + verify the http path/method.
 */
final class MediaTest extends TestCase
{
    private RecordingHttpClient $http;
    private Media $media;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->media = new Media($this->http);
    }

    #[Test]
    public function upload_by_url_posts_to_media_upload(): void
    {
        $this->media->uploadByUrl('https://cdn/a.jpg');
        $req = $this->http->lastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/media/upload', $req['path']);
    }

    #[Test]
    public function single_string_url_wraps_in_url_key(): void
    {
        $this->assertSame(['url' => 'https://x/a.jpg'], $this->media->buildBody('https://x/a.jpg'));
    }

    #[Test]
    public function list_of_string_urls_wraps_in_urls_key(): void
    {
        $this->assertSame(
            ['urls' => ['https://x/a.jpg', 'https://x/b.jpg']],
            $this->media->buildBody(['https://x/a.jpg', 'https://x/b.jpg'])
        );
    }

    #[Test]
    public function list_of_object_specs_wraps_in_images_key(): void
    {
        // Matches the products/news image-spec shape — partners can use
        // the same payload generator across resources.
        $specs = [
            ['url' => 'https://x/a.jpg', 'alt' => 'A'],
            ['url' => 'https://x/b.jpg', 'alt' => 'B'],
        ];
        $this->assertSame(['images' => $specs], $this->media->buildBody($specs));
    }

    #[Test]
    public function empty_array_input_produces_empty_urls(): void
    {
        // Caller still sent an array intentionally — the server will
        // reject (validation), but the SDK doesn't lose the shape on the
        // way out.
        $this->assertSame(['urls' => []], $this->media->buildBody([]));
    }
}
