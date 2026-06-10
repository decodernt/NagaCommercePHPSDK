<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Discounts resource — store-wide auto-applied discount RULE CRUD.
 *
 * Distinct from coupons: coupons require a code; discount rules apply
 * automatically when their conditions match.
 *
 * `rule_type` keys the `config` blob's expected schema. Common values
 * seen in the install: `rule_itemsaleprice`, `rule_categorydiscount`,
 * `rule_freeshipping`, `rule_buyXgetY`. Each rule_type expects different
 * keys inside config — the API persists the blob verbatim, so partners
 * building rule editors keep full schema control.
 *
 * `system_default: true` rules ship with the install and cannot be
 * deleted (toggle `enabled` instead). The server returns 409 for that
 * case.
 *
 * Scopes: `discounts.read` / `discounts.write` / `discounts.delete`.
 */
class Discounts
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List discount rules. Optional query params:
     *   - enabled (0/1)
     *   - rule_type (string, e.g. 'rule_itemsaleprice')
     *   - start, limit
     */
    public function list(array $params = []): Response
    {
        return $this->http->get('/discounts/', $params);
    }

    public function get(int $discountId): Response
    {
        return $this->http->get('/discounts/' . $discountId);
    }

    /**
     * Create a discount rule. Required: `name`, `rule_type`.
     * Common optional:
     *   - config (array or JSON string)  — rule_type-specific payload
     *   - enabled (bool, default true)
     *   - sort_order (int)
     *   - halts (bool — stop further rules when this one matches)
     *   - max_uses, expires (ISO 8601 or unix int)
     *   - start_date (ISO 8601 or unix int)
     *   - countdown (int)
     *   - free_shipping_message (string)
     *   - free_shipping_msg_location (string)
     *   - exclude_customer_group_ids (int[] or CSV)
     *   - apply_in_price_lists (int[] or CSV)
     */
    public function create(array $data): Response
    {
        return $this->http->post('/discounts/', $data);
    }

    public function update(int $discountId, array $data): Response
    {
        return $this->http->put('/discounts/' . $discountId, $data);
    }

    /**
     * Delete a discount rule. Returns 409 for system_default rules —
     * those have to live (toggle `enabled` instead).
     */
    public function delete(int $discountId): Response
    {
        return $this->http->delete('/discounts/' . $discountId);
    }
}
