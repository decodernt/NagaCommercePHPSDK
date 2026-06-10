<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Orders;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pin the Orders URL contract. Specifically guards the
 * /orders/order/{...} subpath — the previous SDK called /orders/{token}
 * directly which the server doesn't expose.
 */
final class OrdersTest extends TestCase
{
    private RecordingHttpClient $http;
    private Orders $orders;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->orders = new Orders($this->http);
    }

    #[Test]
    public function list_hits_orders_list(): void
    {
        $this->orders->list(['limit' => 50, 'status' => 11]);
        $req = $this->http->lastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/orders/list', $req['path']);
        $this->assertSame(['limit' => 50, 'status' => 11], $req['query']);
    }

    #[Test]
    public function count_hits_orders_count(): void
    {
        $this->orders->count();
        $this->assertSame('/orders/count', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function updated_since_includes_timestamp(): void
    {
        $this->orders->updatedSince(1717200000);
        $this->assertSame('/orders/updated-since/1717200000', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function get_uses_order_subpath_with_token(): void
    {
        // Token is 32-char hex; the regex `[a-f0-9]{32}` is what the server
        // accepts, so anything else 404s. Using a valid-shaped token here.
        $token = str_repeat('a1b2', 8); // 32 chars
        $this->orders->get($token);
        $req = $this->http->lastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/orders/order/' . $token, $req['path']);
    }

    #[Test]
    public function create_posts_to_orders_create(): void
    {
        $this->orders->create(['customer' => ['id' => 42], 'items' => []]);
        $req = $this->http->lastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/orders/create', $req['path']);
    }

    #[Test]
    public function cancel_posts_to_order_cancel_with_token_in_body(): void
    {
        $token = str_repeat('a1b2', 8);
        $this->orders->cancel(1025, $token);
        $req = $this->http->lastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/orders/order/1025/cancel', $req['path']);
        $this->assertSame($token, $req['body']['order_token']);
    }

    #[Test]
    public function update_status_puts_to_order_status_subpath(): void
    {
        $this->orders->updateStatus(1025, 11, 'Ready');
        $req = $this->http->lastRequest();
        $this->assertSame('PUT', $req['method']);
        $this->assertSame('/orders/order/1025/status', $req['path']);
        $this->assertSame(11, $req['body']['status']);
        $this->assertSame('Ready', $req['body']['comment']);
    }

    #[Test]
    public function update_status_omits_comment_when_empty(): void
    {
        $this->orders->updateStatus(1025, 11);
        $body = $this->http->lastRequest()['body'];
        $this->assertSame(11, $body['status']);
        $this->assertArrayNotHasKey('comment', $body);
    }
}
