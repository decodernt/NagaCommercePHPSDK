<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Media resource — /api/media. Scope: media.write.
 *
 * Standalone media library access. Uploads images by URL — the server
 * downloads each via MEDIAMANAGER (same path the per-entity flows use),
 * dedupes against existing media by remote URL + content hash, and
 * returns the resulting media rows.
 *
 * Used when you want media in the library WITHOUT attaching to a specific
 * product/news/brand/category right away:
 *   - Artisan-block editors that embed `media_id` in their block JSON.
 *   - Pre-uploading a batch of media before deciding which products /
 *     articles will use which.
 *   - Drafting flows where the entity doesn't exist yet.
 *
 * For attached uploads (where the media is being added to a specific
 * product / article / brand / category), use that resource's image-import
 * path instead — same URL→media resolution, but the attachment is wired up
 * for you.
 */
class Media
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Upload one or more media files by URL. Returns per-URL outcomes;
     * a single broken URL doesn't fail the whole batch.
     *
     * Accepts any of three input shapes:
     *
     *   uploadByUrl('https://...')                    // single
     *   uploadByUrl(['https://...', 'https://...'])   // multiple bare URLs
     *   uploadByUrl([                                 // multiple with metadata
     *       ['url' => '...', 'alt' => '...'],
     *       ['url' => '...'],
     *   ])
     *
     * The `alt` metadata flows through to the resolver's per-spec output
     * (returned in `results[].alt`) but is NOT written to the media row
     * itself — `media.mediatitle` / `media.mediadescription` are library-
     * wide fields shared across every entity using the same media; the
     * /media/upload endpoint deliberately leaves them alone.
     *
     * @param string|array $input  see shapes above
     * @return Response  data: { uploaded, failed, results }
     *                   results: [{ url, success, media_id?, media?, error? }]
     */
    public function uploadByUrl($input): Response
    {
        $body = $this->buildBody($input);
        return $this->http->post('/media/upload', $body);
    }

    /**
     * Normalize the convenience arg shapes into the wire format the server
     * expects. Pure function — extracted so tests can pin the mapping
     * without an HTTP round-trip.
     *
     * @param string|array $input
     * @return array
     */
    public function buildBody($input): array
    {
        if (is_string($input)) {
            return ['url' => $input];
        }
        if (!is_array($input)) {
            return [];
        }
        if (empty($input)) {
            return ['urls' => []];
        }
        // List of URLs vs list of objects vs already-wrapped {url: ...}:
        // peek at the first entry. If it's an object with a `url` key, use
        // the `images` shape; if it's a string, use `urls`.
        $first = reset($input);
        if (is_array($first) && isset($first['url'])) {
            return ['images' => array_values($input)];
        }
        if (is_string($first)) {
            return ['urls' => array_values($input)];
        }
        // Caller passed a pre-wrapped {url: ...} or {urls: [...]} payload —
        // trust it.
        return $input;
    }
}
