<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Client;
use PHPUnit\Framework\TestCase;

/**
 * Base class for live-API integration tests.
 *
 * Wires up the SDK Client from environment variables. Marks the test
 * skipped (with a clear message) when the env isn't configured, so:
 *
 *   - Unit-test runs (phpunit.xml) don't accidentally hit the live API.
 *   - CI / local dev still passes without integration credentials.
 *   - A developer running `phpunit -c phpunit-integration.xml` without
 *     the env set gets a useful "you forgot to set NC_API_URL" message,
 *     not a network timeout.
 *
 * Required env vars:
 *   NC_API_URL        e.g. https://www.nagacommerce.test/api
 *   NC_API_KEY        valid API key on the target store, with all scopes
 *                     enabled for full coverage
 *
 * Optional:
 *   NC_TEST_IMAGE     stable, downloadable image URL (default: a small
 *                     internal asset; override if your store firewalls
 *                     the default)
 *   NC_VERIFY_SSL     "0" to disable SSL peer/host verification (set
 *                     this for local *.test domains with self-signed
 *                     certs). Default "1".
 *
 * Tests are NOT hermetic — there's no cleanup. Created records (products,
 * orders, customers, etc.) accumulate in the target store. Every test
 * uses timestamped unique identifiers (SKUs, names, slugs) so re-runs
 * don't clash with prior runs' leftovers.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Client $client;
    protected string $testImageUrl;

    /** Tag prepended to every test-created identifier so test data is greppable later. */
    protected const TEST_TAG = 'sdkit';

    /** Unique-enough run identifier — millisecond timestamp + 4 random hex chars. */
    protected static string $runId;

    public static function setUpBeforeClass(): void
    {
        // One run id per PHPUnit process — used in unique IDs so all
        // records from this run share a prefix you can grep / batch
        // delete later if you ever decide to clean up.
        if (!isset(self::$runId)) {
            self::$runId = date('YmdHis') . '-' . bin2hex(random_bytes(2));
        }
    }

    protected function setUp(): void
    {
        $url = getenv('NC_API_URL');
        $key = getenv('NC_API_KEY');
        if ($url === false || $url === '' || $key === false || $key === '') {
            $this->markTestSkipped(
                'Integration tests skipped — set NC_API_URL and NC_API_KEY env vars to enable. ' .
                'See phpunit-integration.xml for details.'
            );
        }

        $verifySsl = getenv('NC_VERIFY_SSL');
        $options = [];
        if ($verifySsl === '0' || $verifySsl === 'false') {
            $options['verify_ssl'] = false;
        }

        $this->client = new Client($url, $key, 60, $options);

        $imageOverride = getenv('NC_TEST_IMAGE');
        $this->testImageUrl = ($imageOverride !== false && $imageOverride !== '')
            ? $imageOverride
            : 'https://assets.nagacommerce.com/storage/991c0716-0b1b-4350-9a7b-19d80151602e/uploads/multimedia/2026/05/K8Sh/chatgpt.image.may.14.2026.02.25.18.pm.png';
    }

    /**
     * Unique identifier suffix for this run + this test. Use in SKUs,
     * brand names, slugs, etc. so re-running the suite doesn't trip
     * over its own leftovers from prior runs.
     *
     * Example: $this->uid('product') → "sdkit-20260609103045-ab12-product-1717"
     */
    protected function uid(string $hint = ''): string
    {
        // The microsecond suffix breaks ties when several tests run in
        // the same second and produce data the unique-key indexes care
        // about (brand name, product SKU, news slug, ...).
        $micro = sprintf('%04d', (int)(microtime(true) * 10000) % 10000);
        $hint = $hint !== '' ? '-' . $hint : '';
        return self::TEST_TAG . '-' . self::$runId . $hint . '-' . $micro;
    }

    /** @var string[]|null Cached scope list from sync/verify. */
    private static ?array $scopesCache = null;

    /**
     * Skip the current test when the API key doesn't grant $scope.
     * Scope match is wildcard-aware: a `products.*` grant satisfies
     * `products.read`, `products.write`, etc.
     */
    protected function requireScope(string $scope): void
    {
        if (self::$scopesCache === null) {
            $data = $this->client->sync()->verify()->getData();
            self::$scopesCache = $data['scopes'] ?? [];
        }
        $prefix = explode('.', $scope, 2)[0];
        foreach (self::$scopesCache as $granted) {
            if ($granted === $scope || $granted === $prefix . '.*') {
                return;
            }
        }
        $this->markTestSkipped(
            "API key is missing scope `{$scope}`. Granted scopes: " . implode(', ', self::$scopesCache) .
            ". Add the scope to the key (or set NC_API_KEY to a different key) to enable this test."
        );
    }

    /**
     * Skip the current test unless the key holds AT LEAST ONE of the
     * candidate scopes. Used by routes that accept either a new scope
     * or a legacy alias (e.g. `modules.read` OR `system.settings` on
     * the system/modules listing endpoints).
     *
     * @param string[] $scopes
     */
    protected function requireAnyScope(array $scopes): void
    {
        if (self::$scopesCache === null) {
            $data = $this->client->sync()->verify()->getData();
            self::$scopesCache = $data['scopes'] ?? [];
        }
        foreach ($scopes as $scope) {
            $prefix = explode('.', $scope, 2)[0];
            foreach (self::$scopesCache as $granted) {
                if ($granted === $scope || $granted === $prefix . '.*') {
                    return;
                }
            }
        }
        $this->markTestSkipped(
            'API key has none of the required scopes: ' . implode(', ', $scopes) .
            '. Granted: ' . implode(', ', self::$scopesCache) . '.'
        );
    }
}
