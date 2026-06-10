<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Reference;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the Reference SDK resource. Verifies each method targets the
 * correct /reference/* path. Server-side data shape is pinned by the
 * Lookups service tests on the API side; the cross-repo contract test
 * verifies these paths match real server routes.
 */
final class ReferenceTest extends TestCase
{
    private RecordingHttpClient $http;
    private Reference $reference;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->reference = new Reference($this->http);
    }

    #[Test]
    public function currencies_hits_reference_currencies(): void
    {
        $this->reference->currencies();
        $r = $this->http->lastRequest();
        $this->assertSame('GET', $r['method']);
        $this->assertSame('/reference/currencies', $r['path']);
    }

    #[Test]
    public function customer_groups_hits_reference_customer_groups(): void
    {
        $this->reference->customerGroups();
        $this->assertSame('/reference/customer-groups', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function tax_classes_hits_reference_tax_classes(): void
    {
        $this->reference->taxClasses();
        $this->assertSame('/reference/tax-classes', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function availabilities_hits_reference_availabilities(): void
    {
        $this->reference->availabilities();
        $this->assertSame('/reference/availabilities', $this->http->lastRequest()['path']);
    }
}
