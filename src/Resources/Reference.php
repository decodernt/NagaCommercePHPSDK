<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Reference resource — read-only lookup-table endpoints for the FK-shaped
 * fields used elsewhere in the API. Scope: `reference.read`.
 *
 * Partners writing orders / products / customers need to know the valid
 * values for these reference fields:
 *
 *   currencies()       → orders.currency_id
 *   customerGroups()   → customers.custgroupid + orders.customer.group_id
 *   taxClasses()       → products.tax_class_id
 *   availabilities()   → products.availability_id (integer `id`; see
 *                         schema note below)
 *
 * The corresponding write endpoints validate these ids server-side and
 * return HTTP 400 for unknown values. Driving a partner UI's selectors
 * from this resource guarantees the chosen value passes server-side
 * validation.
 *
 * Schema note on `availabilities()`: the `products.prodavailability`
 * column is declared `varchar(250)`, but the actual stored values are
 * integer-shaped strings (`'1'`, `'2'`, ...) — i.e. the `availid`. The
 * admin form, the importer, and the storefront all treat it as the
 * integer availid. Partners should pass the integer `id` from
 * `availabilities()` as `availability_id` on product writes. The `title`
 * field on each row is the internal availtitle language-key (e.g.
 * `"AvailAvailable"`), not the storefront-displayed label — useful for
 * admin reference but NOT to send back.
 */
class Reference
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Active currencies. Each row:
     *   { id, code, name, symbol, exchange_rate, decimals, is_default, is_active }
     *
     * Use the integer `id` for `orders()->create()` `currency_id`. `code`
     * is the ISO 4217 three-letter code (e.g. EUR, USD).
     */
    public function currencies(): Response
    {
        return $this->http->get('/reference/currencies');
    }

    /**
     * Customer groups. Each row:
     *   { id, name, discount, discount_method, is_default }
     *
     * Use the integer `id` for `customers()->create()` `custgroupid` and
     * `orders()->create()` `customer.group_id`.
     */
    public function customerGroups(): Response
    {
        return $this->http->get('/reference/customer-groups');
    }

    /**
     * Tax classes. Each row: { id, name }. Use the integer `id` for
     * `products()->create()` / `update()` `tax_class_id`.
     */
    public function taxClasses(): Response
    {
        return $this->http->get('/reference/tax-classes');
    }

    /**
     * Product availability options. Each row:
     *   { id, title, color, enabled, sort_order }
     *
     * Pass the integer `id` (availid) as `availability_id` on
     * `products()->create()` / `update()`. The `title` field is the
     * internal availtitle language-key (e.g. `"AvailAvailable"`,
     * `"Availin4-10days"`) — useful for admin reference but not what
     * partners send back. Schema note: the underlying column
     * `products.prodavailability` is `varchar(250)` but the stored
     * values are integer-shaped strings ('1', '2', ...) — the availid.
     */
    public function availabilities(): Response
    {
        return $this->http->get('/reference/availabilities');
    }

    // -----------------------------------------------------------------
    // Write operations (scope: reference.write / reference.delete)
    // -----------------------------------------------------------------

    /**
     * Create a currency. Required: `code` (ISO 4217, 3 chars), `name`.
     * Optional: `exchange_rate`, `symbol`, `symbol_position` ('left'
     * or 'right'), `decimal_string`, `thousand_string`, `decimal_places`,
     * `is_default` (server demotes any prior default in the same
     * transaction), `enabled`, `converter_code`.
     */
    public function createCurrency(array $data): Response
    {
        return $this->http->post('/reference/currencies', $data);
    }

    public function updateCurrency(int $currencyId, array $data): Response
    {
        return $this->http->put('/reference/currencies/' . $currencyId, $data);
    }

    /**
     * Delete a currency. Refuses (409) when the target is the default —
     * promote another currency first via update(..., ['is_default' => true]).
     */
    public function deleteCurrency(int $currencyId): Response
    {
        return $this->http->delete('/reference/currencies/' . $currencyId);
    }

    /**
     * Create a tax class. Required: `name`. The class ID is what
     * products reference via `tax_class_id`.
     */
    public function createTaxClass(array $data): Response
    {
        return $this->http->post('/reference/tax-classes', $data);
    }

    public function updateTaxClass(int $taxClassId, array $data): Response
    {
        return $this->http->put('/reference/tax-classes/' . $taxClassId, $data);
    }

    /**
     * Delete a tax class. Products that were referencing it fall back
     * to tax_class_id = 0 (the storefront's "no class" default).
     */
    public function deleteTaxClass(int $taxClassId): Response
    {
        return $this->http->delete('/reference/tax-classes/' . $taxClassId);
    }

    /**
     * Create a product availability option. Required: `title` (the
     * internal language-key). Optional: `color` (CSS-class hint —
     * 'in-stock' default), `enabled`, `sort_order`.
     */
    public function createAvailability(array $data): Response
    {
        return $this->http->post('/reference/availabilities', $data);
    }

    public function updateAvailability(int $availId, array $data): Response
    {
        return $this->http->put('/reference/availabilities/' . $availId, $data);
    }

    public function deleteAvailability(int $availId): Response
    {
        return $this->http->delete('/reference/availabilities/' . $availId);
    }
}
