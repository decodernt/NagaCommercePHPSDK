<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Client;
use NagaCommerce\SDK\Exceptions\ApiException;
use NagaCommerce\SDK\Exceptions\AuthenticationException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cross-cutting concerns that aren't tied to a single resource:
 *   - missing API key → 401 with the documented envelope
 *   - bad API key → 401 invalid_key
 *   - unknown resource id → 404 with `Not Found` + a `detail` string
 *   - validation error → 400 with a `detail` mentioning the offending field
 *   - JSON:API envelope shape: `errors[].status`, `errors[].title`,
 *     `errors[].detail`, plus the top-level `jsonapi.version`
 *
 * These tests deliberately swap out the suite's default authenticated
 * client for an alternate one (no key, wrong key) to exercise the
 * auth boundary without losing the rest of the test harness.
 */
final class AuthAndErrorShapeTest extends IntegrationTestCase
{
    /**
     * Build a Client with a different key (or empty) but reusing all the
     * same connection settings as the default test client (URL, SSL verify
     * flag from env).
     */
    private function clientWithKey(string $key): Client
    {
        $url = (string)getenv('NC_API_URL');
        $verifySsl = getenv('NC_VERIFY_SSL');
        $options = [];
        if ($verifySsl === '0' || $verifySsl === 'false') {
            $options['verify_ssl'] = false;
        }
        // No retries — these tests want the first response back, not a
        // backoff loop.
        $options['max_retries'] = 0;
        return new Client($url, $key, 30, $options);
    }

    #[Test]
    public function missing_api_key_returns_401(): void
    {
        $bad = $this->clientWithKey('');
        try {
            $bad->sync()->verify();
            $this->fail('Expected 401 without an API key');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('Unauthorized', $e->getErrorTitle());
            // Error detail should explain what's missing.
            $this->assertStringContainsString('API key', $e->getErrorDetail());
        }
    }

    #[Test]
    public function bad_api_key_returns_401_invalid_key(): void
    {
        $bad = $this->clientWithKey('ngc_live_definitely_not_a_real_key_aaaaaaaaaaaa');
        try {
            $bad->sync()->verify();
            $this->fail('Expected 401 for an invalid API key');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertStringContainsString('Invalid', $e->getErrorDetail());
        }
    }

    #[Test]
    public function unknown_product_id_returns_404_with_detail(): void
    {
        $this->requireScope('products.read');
        try {
            $this->client->products()->get(999999999);
            $this->fail('Expected 404 for unknown product id');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('Not Found', $e->getErrorTitle());
            $this->assertNotEmpty($e->getErrorDetail());
        }
    }

    #[Test]
    public function unknown_brand_id_returns_404(): void
    {
        $this->requireScope('brands.write');
        try {
            $this->client->brands()->update(999999999, ['brandslug' => 'doesnt-matter']);
            $this->fail('Expected 404 for unknown brand id');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('Not Found', $e->getErrorTitle());
        }
    }

    #[Test]
    public function validation_error_carries_offending_field_in_detail(): void
    {
        $this->requireScope('products.write');
        try {
            // Numeric tax_class_id that doesn't exist → reference validation kicks in.
            $this->client->products()->create([
                'name'         => $this->uid('AuthBad'),
                'sku'          => $this->uid('authbad'),
                'price'        => 1.0,
                'tax_class_id' => 999999,
            ]);
            $this->fail('Expected 400 for unknown tax_class_id');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            // Detail must name the field — that's what makes the error
            // actionable for a partner integration.
            $this->assertStringContainsString('tax_class_id', $e->getErrorDetail());
        }
    }

    #[Test]
    public function error_envelope_is_jsonapi_shaped(): void
    {
        // Use raw cURL so we can see the full envelope on the wire — the
        // SDK's exception only carries (statusCode, title, detail) and
        // strips the rest. We assert the on-wire shape directly here so a
        // breaking change to the envelope shows up as a single test
        // failure rather than 50 noisy assertion fails across the suite.
        $url = (string)getenv('NC_API_URL') . '/products/product/999999999';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . (string)getenv('NC_API_KEY'),
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => getenv('NC_VERIFY_SSL') === '0' ? false : true,
            CURLOPT_SSL_VERIFYHOST => getenv('NC_VERIFY_SSL') === '0' ? 0 : 2,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $env = json_decode((string)$body, true);
        $this->assertIsArray($env);

        // Top-level keys per the JSON:API envelope.
        $this->assertArrayHasKey('jsonapi', $env);
        $this->assertSame('1.0', $env['jsonapi']['version']);
        $this->assertArrayHasKey('errors', $env);
        $this->assertNotEmpty($env['errors']);

        $err = $env['errors'][0];
        $this->assertArrayHasKey('status', $err);
        $this->assertArrayHasKey('title', $err);
        $this->assertArrayHasKey('detail', $err);
        $this->assertSame('404', (string)$err['status']);
    }
}
