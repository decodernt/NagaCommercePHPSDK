<?php

declare(strict_types=1);

use NagaCommerce\SDK\Client;
use NagaCommerce\SDK\Tests\Contract\RouteParser;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cross-repo contract test.
 *
 * Goal: catch the bug class where the SDK calls a path that doesn't exist
 * on the server (we just shipped a fix for this — the old SDK called
 * `/products/{id}` while the server route is `/products/product/{id}`).
 *
 * How it works:
 *   1. Parse every controller in /includes/api/ of the sibling nagaCommerce
 *      repo, extracting `(method, pattern)` tuples. The pattern is the
 *      server-side regex literal (e.g. `/products/product/(\d+)`).
 *   2. Drive every SDK method through a recording HttpClient with concrete
 *      sample arguments.
 *   3. For each recorded request, assert at least one server route matches.
 *
 * If the sibling repo can't be found (e.g. running CI from a separate
 * checkout), skip the test rather than fail loudly — the per-resource tests
 * still pin the SDK side independently.
 */
final class ApiRouteContractTest extends TestCase
{
    /** @var array<int, array{method:string, pattern:string, source_file:string}> */
    private static array $serverRoutes = [];

    public static function setUpBeforeClass(): void
    {
        // Look for the sibling repo. Layout is parallel: both repos sit
        // next to each other in ~/Sites or wherever the dev keeps source.
        $sdkRoot = dirname(__DIR__, 2);
        $apiDir = dirname($sdkRoot) . '/nagaCommerce/includes/api';
        if (!is_dir($apiDir)) {
            self::markTestSkippedStatic('nagaCommerce repo not found next to SDK at ' . $apiDir);
        }
        require_once __DIR__ . '/RouteParser.php';
        self::$serverRoutes = RouteParser::parseDir($apiDir);
        if (empty(self::$serverRoutes)) {
            self::markTestSkippedStatic('No server routes parsed — RouteParser may need updating');
        }
    }

    private static function markTestSkippedStatic(string $msg): void
    {
        // PHPUnit's markTestSkipped throws SkippedTestError; need a test
        // method context to call it. Defer to the first test method instead.
        self::$serverRoutes = [['__skip__' => true, 'reason' => $msg]];
    }

    private function skipIfNoRoutes(): void
    {
        if (isset(self::$serverRoutes[0]['__skip__'])) {
            $this->markTestSkipped(self::$serverRoutes[0]['reason']);
        }
    }

    /**
     * Drive every documented SDK method against the recorder and assert each
     * recorded request matches a server route. One test method, many
     * assertions — keeps the per-method test count low while still failing
     * fast on the first drift.
     */
    #[Test]
    public function every_sdk_method_targets_a_real_server_route(): void
    {
        $this->skipIfNoRoutes();

        $http = new RecordingHttpClient();
        $client = $this->makeClientWith($http);

        // Sample arguments for every SDK method. Real values shouldn't
        // matter — we only care that the SDK builds a syntactically valid
        // path that matches the server regex.
        $token = str_repeat('a1b2', 8); // 32-char hex

        // Products
        $client->products()->count();
        $client->products()->updatedSince(1717200000);
        $client->products()->get(123);
        $client->products()->search();
        $client->products()->create(['name' => 'X']);
        $client->products()->update(123, ['price' => 1.0]);
        $client->products()->delete(123);
        $client->products()->batchCreate([['name' => 'X']]);
        $client->products()->batchUpdate([['id' => 1, 'name' => 'X']]);
        $client->products()->batchDelete([1, 2]);
        $client->products()->adjustStock([['product_id' => 1, 'delta' => 1]]);
        $client->products()->setStock([['product_id' => 1, 'quantity' => 1]]);
        $client->products()->getCustomFields(123);
        $client->products()->replaceCustomFields(123, []);
        $client->products()->appendCustomFields(123, []);
        $client->products()->removeCustomField(123, 7, 12);
        $client->products()->clearCustomFields(123);
        $client->products()->listCustomFieldDefinitions();
        $client->products()->createCustomFieldLabel('Color');
        $client->products()->updateCustomFieldLabel(7, []);
        $client->products()->deleteCustomFieldLabel(7);
        $client->products()->createCustomFieldValue(7, 'Red');
        $client->products()->updateCustomFieldValue(12, []);
        $client->products()->deleteCustomFieldValue(12);

        // Orders
        $client->orders()->list();
        $client->orders()->count();
        $client->orders()->updatedSince(1717200000);
        $client->orders()->get($token);
        $client->orders()->create([]);
        $client->orders()->cancel(1025, $token);
        $client->orders()->updateStatus(1025, 11);

        // Brands
        $client->brands()->list();
        $client->brands()->search();
        $client->brands()->create(['brandname' => 'X']);
        $client->brands()->update(5, []);

        // Categories
        $client->categories()->list();
        $client->categories()->search();
        $client->categories()->get(5);
        $client->categories()->create(['catname' => 'X']);
        $client->categories()->update(5, []);
        $client->categories()->batchCreate([['catname' => 'X']]);

        // Customers
        $client->customers()->docs();
        $client->customers()->docs('create');
        $client->customers()->get(42);
        $client->customers()->search();
        $client->customers()->create([['email' => 'a@x.com']]);
        $client->customers()->update(['id' => 42]);
        $client->customers()->updateByEmail('jane@example.com', []);
        $client->customers()->delete(42);

        // Pricelists
        $client->pricelists()->list();
        $client->pricelists()->items(7);

        // News
        $client->news()->listArticles();
        $client->news()->listArticles('press');
        $client->news()->getArticle('our-product');
        $client->news()->searchArticles('summer');
        $client->news()->listCategories();
        $client->news()->getCategory('press');
        $client->news()->searchCategories();
        $client->news()->createArticle(['newstitle' => 'X']);
        $client->news()->updateArticle(500, ['newstitle' => 'X']);
        $client->news()->deleteArticle(500);

        // Export (POST, query baked into the path; the recorder strips it back out)
        $client->export()->products();

        // Sync
        $client->sync()->verify();
        $client->sync()->status();

        // Media — standalone uploader
        $client->media()->uploadByUrl('https://cdn/a.jpg');

        // Modules — list discovery endpoints (wraps /system/modules/...)
        $client->modules()->payment();
        $client->modules()->shipping();
        $client->modules()->analytics();
        $client->modules()->addons();

        // Reference — lookup tables (currencies, customer groups, tax classes, availabilities)
        $client->reference()->currencies();
        $client->reference()->customerGroups();
        $client->reference()->taxClasses();
        $client->reference()->availabilities();

        $unmatched = [];
        foreach ($http->calls as $call) {
            if (!$this->matchesAnyServerRoute($call['method'], $call['path'])) {
                $unmatched[] = $call['method'] . ' ' . $call['path'];
            }
        }
        $this->assertEmpty(
            $unmatched,
            "The following SDK calls don't match any server route:\n  "
                . implode("\n  ", $unmatched)
                . "\nServer routes parsed: " . count(self::$serverRoutes)
        );

        // Sanity check: we actually exercised every SDK method we expect.
        $this->assertGreaterThanOrEqual(45, $http->callCount(), 'SDK coverage shrunk?');
    }

    private function matchesAnyServerRoute(string $method, string $path): bool
    {
        foreach (self::$serverRoutes as $route) {
            if (isset($route['__skip__'])) { continue; }
            if (strtoupper($route['method']) !== $method) { continue; }
            // The server route matches optional trailing slash (so an SDK
            // call to `/customers/update/` lands on the `/update` route).
            $regex = '#^' . $route['pattern'] . '/?$#';
            if (@preg_match($regex, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a Client with a pre-injected recording HttpClient via reflection,
     * skipping the real constructor (which would try to set up cURL).
     */
    private function makeClientWith(RecordingHttpClient $http): Client
    {
        $ref = new ReflectionClass(Client::class);
        $client = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($client, $http);
        return $client;
    }

    /**
     * Diagnostic: list the server routes the parser found. Useful when the
     * contract test fails — confirm the parser is seeing what you expect.
     */
    #[Test]
    public function parser_finds_at_least_the_known_product_routes(): void
    {
        $this->skipIfNoRoutes();

        $productRoutes = array_filter(
            self::$serverRoutes,
            fn($r) => isset($r['pattern']) && strpos($r['pattern'], '/products') === 0
        );
        $this->assertGreaterThan(15, count($productRoutes),
            'Parser should find > 15 /products routes; got ' . count($productRoutes));

        // Spot check a few known patterns.
        $patterns = array_column($productRoutes, 'pattern');
        $this->assertContains('/products/count', $patterns);
        $this->assertContains('/products/product/(\\d+)', $patterns);
        $this->assertContains('/products/batch/create', $patterns);
        $this->assertContains('/products/inventory/set-stock', $patterns);
        $this->assertContains('/products/product/(\\d+)/custom-fields', $patterns);
    }
}
