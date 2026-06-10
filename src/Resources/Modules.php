<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Modules resource — wraps the existing /api/system/modules/{type}/list
 * and /api/system/addons/list endpoints.
 *
 * Scopes: `modules.read` (list) / `modules.write` (enable, disable).
 * Legacy `system.settings` keys continue to satisfy both for now.
 *
 * Use case: order-creation flows that need to assign a SPECIFIC payment
 * or shipping module rather than letting the server pick something at
 * random. Call `payment()` / `shipping()` to discover the actual module
 * ids your store has enabled + configured, then pass the chosen id as
 * `payment_method` or `shipping.module` on `orders()->create()`.
 *
 * The order-create endpoint now validates these ids server-side — sending
 * an unknown / unconfigured module returns HTTP 400 instead of silently
 * routing the order to a default. Use the listing here to drive a partner
 * UI's module selector so callers can never pick an invalid value.
 *
 * Filter argument values match the URL path segment the server expects:
 *   - 'all'                — everything in the modules directory
 *   - 'enabled'            — only modules with EnableService set
 *   - 'enabled-configured' — enabled AND fully configured (the safe set
 *                            for partner-driven order creation)
 *
 * The shipping/payment validation in orders()->create() filters using
 * 'enabled-configured', so when you're driving a module selector for
 * order creation, list with that same filter.
 */
class Modules
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List payment (checkout) modules. The server uses the directory name
     * `checkout`, but the user-facing concept on the storefront is
     * "payment" — same data either way.
     *
     * @param string $filter 'all' | 'enabled' | 'enabled-configured'
     */
    public function payment(string $filter = 'enabled-configured'): Response
    {
        return $this->http->get('/system/modules/checkout/list/' . $this->validateFilter($filter));
    }

    /**
     * List shipping modules.
     *
     * @param string $filter 'all' | 'enabled' | 'enabled-configured'
     */
    public function shipping(string $filter = 'enabled-configured'): Response
    {
        return $this->http->get('/system/modules/shipping/list/' . $this->validateFilter($filter));
    }

    /**
     * List analytics modules (Google Analytics, Skroutz Analytics, etc.).
     *
     * @param string $filter 'all' | 'enabled' | 'enabled-configured'
     */
    public function analytics(string $filter = 'enabled-configured'): Response
    {
        return $this->http->get('/system/modules/analytics/list/' . $this->validateFilter($filter));
    }

    /**
     * List installed addons. Same filter contract as the module endpoints.
     *
     * @param string $filter 'all' | 'enabled' | 'enabled-configured'
     */
    public function addons(string $filter = 'enabled-configured'): Response
    {
        return $this->http->get('/system/addons/list/' . $this->validateFilter($filter));
    }

    /**
     * Enable an addon. Idempotent — re-enabling an already-on addon
     * returns success with `changed: false`. Scope: `modules.write`.
     *
     * The server flips the addon in the AddonModules CSV config and
     * rebuilds the data-store's addon-vars cache. Does NOT execute the
     * addon's pre/post-hook setup (downloads, schema bumps); partners
     * that need those should use the addon's dedicated setup endpoint.
     */
    public function enableAddon(string $addonId): Response
    {
        return $this->http->post('/system/addons/' . rawurlencode($addonId) . '/enable');
    }

    /**
     * Disable an addon. Clears its `is_setup` module_var so re-enabling
     * later re-triggers the setup prompt. Scope: `modules.write`.
     */
    public function disableAddon(string $addonId): Response
    {
        return $this->http->post('/system/addons/' . rawurlencode($addonId) . '/disable');
    }

    /**
     * Guard against typos client-side — the server's URL regex would
     * 404 on an unrecognized filter, but a clear PHP exception at the
     * call site is friendlier.
     */
    private function validateFilter(string $filter): string
    {
        $allowed = ['all', 'enabled', 'enabled-configured'];
        if (!in_array($filter, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Filter must be one of: ' . implode(', ', $allowed) . '. Got: ' . $filter
            );
        }
        return $filter;
    }
}
