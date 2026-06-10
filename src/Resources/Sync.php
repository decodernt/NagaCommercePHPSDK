<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Sync resource — /api/sync. Scope: sync.read.
 *
 * Lightweight metadata endpoints used during connection bring-up and to
 * plan incremental syncs (the per-entity `last_modified` timestamps let
 * you cheaply decide whether anything needs to be pulled).
 */
class Sync
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Verify connectivity + return the calling key's scopes and per-entity
     * capability flags. Useful as a first call in any integration to fail
     * fast on misconfigured keys.
     *
     * Returns: { connected, store_name, store_url, platform, api_version,
     *           scopes: [...], capabilities: { products, categories, brands,
     *           orders, customers } }
     */
    public function verify(): Response
    {
        return $this->http->get('/sync/verify');
    }

    /**
     * Entity counts + last-modified timestamps for products / categories /
     * brands / orders / customers, plus the store name and the server's
     * current UNIX timestamp.
     */
    public function status(): Response
    {
        return $this->http->get('/sync/status');
    }
}
