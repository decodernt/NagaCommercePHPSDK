<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Support;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Test double for HttpClient that records every call instead of making
 * network requests, then returns a scriptable success Response.
 *
 * Why a class hierarchy override instead of an interface? HttpClient is
 * concrete in the SDK (resources are typed against it directly). Extending
 * gives us a no-network replacement without redesigning the Resource
 * constructors.
 *
 * Usage in tests:
 *   $http = new RecordingHttpClient();
 *   (new Products($http))->get(123);
 *   $req = $http->lastRequest();
 *   $this->assertSame('GET', $req['method']);
 *   $this->assertSame('/products/product/123', $req['path']);
 */
final class RecordingHttpClient extends HttpClient
{
    /** @var array<int, array{method:string,path:string,query:array,body:?array}> */
    public array $calls = [];

    /** @var array<int, array> scripted response bodies, one per call in order */
    public array $scriptedBodies = [];

    /** Default body returned when no scripted response is queued. */
    public array $defaultBody = ['jsonapi' => ['version' => '1.0'], 'data' => null, 'meta' => []];

    public function __construct()
    {
        // Skip parent constructor — no network, no baseUrl/key needed.
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->record('GET', $path, $query, null);
    }

    public function post(string $path, array $data = []): Response
    {
        return $this->record('POST', $this->stripQuery($path), $this->parseQuery($path), $data);
    }

    public function put(string $path, array $data = []): Response
    {
        return $this->record('PUT', $path, [], $data);
    }

    public function delete(string $path, array $query = []): Response
    {
        return $this->record('DELETE', $path, $query, null);
    }

    /**
     * Pre-queue a body for the next request. Returned in FIFO order.
     */
    public function script(array $body): void
    {
        $this->scriptedBodies[] = $body;
    }

    public function lastRequest(): array
    {
        if (empty($this->calls)) {
            throw new \LogicException('No calls have been recorded yet.');
        }
        return $this->calls[count($this->calls) - 1];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    private function record(string $method, string $path, array $query, ?array $body): Response
    {
        $this->calls[] = [
            'method' => $method,
            'path'   => $path,
            'query'  => $query,
            'body'   => $body,
        ];
        $responseBody = !empty($this->scriptedBodies)
            ? array_shift($this->scriptedBodies)
            : $this->defaultBody;
        return new Response(200, $responseBody);
    }

    /**
     * The Export resource builds POSTs with the query string already
     * baked into the path. Tests are easier to write when we surface that
     * as a parsed `query` array, so split it out before recording.
     */
    private function stripQuery(string $path): string
    {
        $pos = strpos($path, '?');
        return $pos === false ? $path : substr($path, 0, $pos);
    }

    private function parseQuery(string $path): array
    {
        $pos = strpos($path, '?');
        if ($pos === false) {
            return [];
        }
        parse_str(substr($path, $pos + 1), $parsed);
        return $parsed;
    }
}
