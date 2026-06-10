<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Webhooks resource — partner-managed event subscriptions.
 *
 * The store fires an HTTP POST to your registered URL when the
 * subscribed event happens. Every dispatch carries an
 * `X-NagaCommerce-Signature` header (HMAC-SHA256 of the raw body
 * using the webhook's `secret`); verify it to confirm origin.
 *
 * Known events:
 *   - NewOrderCompleted
 *   - order_status_changed
 *   - multi_delete_products
 *   - product_create_event
 *   - product_option_value_changed
 *   - admin_init_edit_order_quote
 *
 * The controller accepts unknown event names too — addons can fire
 * their own events that aren't enumerated here.
 *
 * Scopes: webhooks.read / webhooks.write / webhooks.delete.
 */
class Webhooks
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List webhooks. Optional filters:
     *   - event_name (string)  — restrict to one event
     *   - enabled (0/1)
     */
    public function list(array $params = []): Response
    {
        return $this->http->get('/webhooks/', $params);
    }

    public function get(int $webhookId): Response
    {
        return $this->http->get('/webhooks/' . $webhookId);
    }

    /**
     * Register a webhook. Required: `url`, `event_name`. Optional:
     *   - `http_method` ('POST' default | 'PUT' | 'PATCH' | 'GET')
     *   - `headers` (object) — partner-side auth + any custom routing
     *     fields. Example:
     *       ['Authorization' => 'Bearer abc', 'X-Api-Key' => 'xyz']
     *     `Content-Type` + `User-Agent` overrides are honored (some
     *     receivers want form-encoded). `X-NagaCommerce-{Event,
     *     Signature, Delivery}` are reserved and silently dropped —
     *     partners can't shadow our signature header via custom config.
     *   - `payload_template` (string) — when set, the dispatcher
     *     renders this string as the request body instead of the
     *     default {event, timestamp, payload} envelope. This is what
     *     makes Slack / Discord / Teams / CRM integrations work, since
     *     each has its own expected body shape. Available substitutions:
     *       {{ event }}, {{ timestamp }}, {{ payload.foo.bar }}, {{ payload }}
     *     Whole-object paths (just `{{ payload }}`) JSON-encode. Slack
     *     example:
     *       {"text": ":bell: New {{ event }} - {{ payload.0 }}"}
     *   - `dispatch_mode` ('queue' default | 'inline'). Queue hands the
     *     delivery to a background worker so the visitor request that
     *     fired the event returns immediately — the right choice for
     *     anything tied to checkout / storefront UX. Inline blocks the
     *     firing request until your endpoint responds; only use it when
     *     debugging a brand-new receiver.
     *   - `secret` (string) — used by the store to sign payloads via
     *     HMAC-SHA256 in `X-NagaCommerce-Signature`. SEPARATE from
     *     partner-side auth: it lets the receiver verify requests
     *     originated from THIS store and weren't tampered with in
     *     transit. Auto-generated (24 hex bytes) when omitted.
     *   - `name` (string — partner-facing display label)
     *   - `enabled` (bool, default true)
     *
     * GET dispatches don't carry a body — the URL must encode whatever
     * identifier the partner needs.
     *
     * Returns 400 for invalid URLs (FILTER_VALIDATE_URL).
     */
    public function create(array $data): Response
    {
        return $this->http->post('/webhooks/', $data);
    }

    public function update(int $webhookId, array $data): Response
    {
        return $this->http->put('/webhooks/' . $webhookId, $data);
    }

    public function delete(int $webhookId): Response
    {
        return $this->http->delete('/webhooks/' . $webhookId);
    }

    /**
     * Fire the configured event against this webhook with a
     * caller-supplied test payload. Useful for verifying the partner's
     * receiver before relying on real events.
     *
     * Body: { payload?: object }. Returns the latest delivery row.
     */
    public function test(int $webhookId, array $payload = []): Response
    {
        return $this->http->post('/webhooks/' . $webhookId . '/test', ['payload' => $payload]);
    }

    /**
     * Per-webhook delivery log (last 50 by default). Each row carries
     * `response_code`, `response_body` (truncated to 1KB),
     * `error_message`, `dispatched_at`.
     */
    public function deliveries(int $webhookId, array $params = []): Response
    {
        return $this->http->get('/webhooks/' . $webhookId . '/deliveries', $params);
    }

    /**
     * Catalog of every event the dispatcher has observed firing.
     * Self-populating — events get registered the first time they
     * trigger, so addon-fired events appear automatically once they
     * occur in production traffic.
     *
     * Each row: { id, event_name, friendly_name, description,
     * addon_assoc, first_seen, last_seen, subscriber_count,
     * sample_payload }. `friendly_name` is a human label derived from
     * the key — use it for UI; use `event_name` as the subscription
     * key. `sample_payload` is the last captured payload (decoded) so
     * consumers can build templates against the real shape instead of
     * guessing. Events the admin has hidden from the catalog are
     * excluded from the list (they're never partner-subscribable).
     *
     * Use the `event_name` value as the `event_name` field when
     * creating a webhook subscription.
     */
    public function events(array $params = []): Response
    {
        return $this->http->get('/webhooks/events', $params);
    }
}
