<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Customers;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CustomersTest extends TestCase
{
    private RecordingHttpClient $http;
    private Customers $customers;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->customers = new Customers($this->http);
    }

    #[Test]
    public function docs_without_action_hits_doc_root(): void
    {
        $this->customers->docs();
        $this->assertSame('/customers/doc/', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function docs_with_action_appends_to_doc_path(): void
    {
        $this->customers->docs('create');
        $this->assertSame('/customers/doc/create', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function get_by_numeric_id_hits_get_subpath(): void
    {
        $this->customers->get(42);
        $this->assertSame('/customers/get/42', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function get_by_email_url_encodes_email(): void
    {
        // The @ has to be url-encoded so the router parameter pattern
        // `[^/]+` sees a single segment with no slash injection risk.
        $this->customers->get('jane@example.com');
        $this->assertSame('/customers/get/jane%40example.com', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function search_without_filters_uses_GET(): void
    {
        $this->customers->search();
        $r = $this->http->lastRequest();
        $this->assertSame('GET', $r['method']);
        $this->assertSame('/customers/search', $r['path']);
    }

    #[Test]
    public function search_with_filters_uses_POST_with_body(): void
    {
        $this->customers->search(['email' => 'jane@example.com']);
        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/customers/search', $r['path']);
        $this->assertSame(['email' => 'jane@example.com'], $r['body']);
    }

    #[Test]
    public function create_wraps_rows_in_customers_envelope(): void
    {
        $rows = [['email' => 'a@x.com'], ['email' => 'b@x.com']];
        $this->customers->create($rows);
        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/customers/create', $r['path']);
        $this->assertSame(['customers' => $rows], $r['body']);
    }

    #[Test]
    public function update_posts_to_update_root(): void
    {
        $this->customers->update(['id' => 42, 'firstname' => 'Jane']);
        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/customers/update/', $r['path']);
    }

    #[Test]
    public function update_by_email_posts_to_email_subpath(): void
    {
        $this->customers->updateByEmail('jane@example.com', ['firstname' => 'Janet']);
        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/customers/update/jane%40example.com', $r['path']);
    }

    #[Test]
    public function delete_uses_POST_under_the_hood(): void
    {
        // The server route is POST /customers/delete/{id}, NOT DELETE.
        // Surprising, but it's the contract.
        $this->customers->delete(42);
        $r = $this->http->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/customers/delete/42', $r['path']);
    }
}
