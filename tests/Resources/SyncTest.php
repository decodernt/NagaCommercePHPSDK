<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Sync;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SyncTest extends TestCase
{
    private RecordingHttpClient $http;
    private Sync $sync;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->sync = new Sync($this->http);
    }

    #[Test]
    public function verify_hits_sync_verify(): void
    {
        $this->sync->verify();
        $this->assertSame('GET', $this->http->lastRequest()['method']);
        $this->assertSame('/sync/verify', $this->http->lastRequest()['path']);
    }

    #[Test]
    public function status_hits_sync_status(): void
    {
        $this->sync->status();
        $this->assertSame('/sync/status', $this->http->lastRequest()['path']);
    }
}
