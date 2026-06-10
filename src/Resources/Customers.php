<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Customers resource — /api/customers.
 *
 * The server uses a custom data-mapper layer so payloads use friendly keys
 * (email, firstname, lastname, ...) rather than the underlying `custcon*`
 * column names. Run getDocStructure() against a live store to see the exact
 * shape expected by create / update on this version.
 *
 * Scopes: customers.view, customers.create, customers.update, customers.delete.
 *
 * Quirks:
 *  - create() ALWAYS expects bulk: `{ "customers": [ {...}, {...} ] }` (max 500).
 *  - delete is a POST (not DELETE) because the server route is /delete/{id}.
 */
class Customers
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Return the API's self-documented payload structure. Pass null to get
     * the whole envelope, or an action ('create' / 'update' / 'search') to
     * narrow it down.
     */
    public function docs(?string $action = null): Response
    {
        $path = '/customers/doc/';
        if ($action !== null && $action !== '') {
            $path .= rawurlencode($action);
        }
        return $this->http->get($path);
    }

    /**
     * Look up a customer by numeric id or email. The server routes both via
     * the same /get/{id} path.
     */
    public function get($idOrEmail): Response
    {
        return $this->http->get('/customers/get/' . rawurlencode((string)$idOrEmail));
    }

    /**
     * Search customers. The server accepts both GET (params on query string)
     * and POST (JSON body). Body keys vary per store — use docs('search').
     */
    public function search(array $filters = []): Response
    {
        if (empty($filters)) {
            return $this->http->get('/customers/search');
        }
        return $this->http->post('/customers/search', $filters);
    }

    /**
     * Bulk-create customers. Up to 500 per call.
     *
     * @param array $customers list of customer payload arrays
     */
    public function create(array $customers): Response
    {
        return $this->http->post('/customers/create', ['customers' => $customers]);
    }

    /**
     * Update one customer by id. The customer payload must include the
     * numeric `id`. For email-keyed updates (no NC id on the integrator
     * side) use updateByEmail() instead.
     *
     * The server's update endpoint is always bulk-shaped, so we wrap the
     * single payload as `{customers: [$customer]}` here — partners get a
     * one-customer ergonomic call without having to know about the bulk
     * envelope.
     */
    public function update(array $customer): Response
    {
        return $this->http->post('/customers/update/', ['customers' => [$customer]]);
    }

    /**
     * Bulk-update customers. Up to 500 per call. Each row must include
     * its numeric `id`.
     */
    public function updateBulk(array $customers): Response
    {
        return $this->http->post('/customers/update/', ['customers' => $customers]);
    }

    /**
     * Update by email — used when integrating systems that don't track the
     * NC customer id.
     */
    public function updateByEmail(string $email, array $customer): Response
    {
        return $this->http->post('/customers/update/' . rawurlencode($email), $customer);
    }

    /**
     * Delete by numeric id. The server route uses POST, not DELETE.
     */
    public function delete(int $customerId): Response
    {
        return $this->http->post('/customers/delete/' . $customerId);
    }

    /**
     * Add a single shipping address to an existing customer. Scope: customers.update.
     *
     * Friendly keys: first_name, last_name, company, address_line_1,
     * address_line_1_num, address_line_2, city, state, state_id, zip,
     * country, country_id, phone, billing_type, company_doy,
     * company_activity, ssn. See `Customers::docs('create')` for the
     * authoritative list per store version.
     */
    public function addAddress(int $customerId, array $address): Response
    {
        return $this->http->post('/customers/' . $customerId . '/addresses/', $address);
    }

    /**
     * Partial update of one address. Only fields you send are written.
     */
    public function updateAddress(int $customerId, int $addressId, array $address): Response
    {
        return $this->http->put('/customers/' . $customerId . '/addresses/' . $addressId, $address);
    }

    /**
     * Remove an address from a customer's address book.
     */
    public function deleteAddress(int $customerId, int $addressId): Response
    {
        return $this->http->delete('/customers/' . $customerId . '/addresses/' . $addressId);
    }

    // ---- Customer groups ---------------------------------------------

    /**
     * List customer groups. Scope: customers.view.
     * Each row carries customergroupid, groupname, discount,
     * discountmethod, isdefault, categoryaccesstype.
     */
    public function listGroups(): Response
    {
        return $this->http->get('/customers/groups/');
    }

    /**
     * Create a customer group. Required: `name`. Optional:
     *   - discount (float)            default 0
     *   - discount_method (string)    'percent' (default) | 'fixed'
     *   - is_default (bool)           promotes this group to default;
     *                                 demotes the previous default in the
     *                                 same transaction (admin parity)
     *   - category_access (string)    'none' | 'all' (default) | 'specific'
     *   - access_category_ids (int[]) required when category_access is 'specific'
     */
    public function createGroup(array $data): Response
    {
        return $this->http->post('/customers/groups/', $data);
    }

    public function getGroup(int $groupId): Response
    {
        return $this->http->get('/customers/groups/' . $groupId);
    }

    public function updateGroup(int $groupId, array $data): Response
    {
        return $this->http->put('/customers/groups/' . $groupId, $data);
    }

    /**
     * Delete a customer group. The default group can't be deleted (409);
     * promote another group first. Customers pointing at the deleted group
     * fall back to the store's default (custgroupid = 0).
     */
    public function deleteGroup(int $groupId): Response
    {
        return $this->http->delete('/customers/groups/' . $groupId);
    }
}
