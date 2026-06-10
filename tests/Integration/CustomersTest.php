<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /customers endpoints — bulk-shaped create / update plus single-key
 * get / delete and the self-documenting docs endpoint.
 *
 * Coverage:
 *   - docs envelope (smoke + per-action)
 *   - bulk create (multi-row, mixed-success, dup-email skip)
 *   - get by id, get by email (same /get/{key} path)
 *   - update by id (bulk-wrapped)
 *   - update by email
 *   - delete (POST not DELETE — server route quirk)
 *   - reference validation: unknown group_id rejection
 *
 * Friendly-key shape from the data mapper: `id`, `email`, `first_name`,
 * `last_name`, `phone`, `company`, `group_id`, etc. The store-side column
 * names (`custconemail`, ...) never leak into the API.
 *
 * No cleanup. Uses uid()-tagged emails to keep runs distinct.
 */
final class CustomersTest extends IntegrationTestCase
{
    private function uniqueEmail(string $hint = 'cust'): string
    {
        return strtolower($this->uid($hint)) . '@sdk-integration.test';
    }

    #[Test]
    public function docs_returns_envelope(): void
    {
        // docs is unscoped — anyone with a valid key can introspect.
        $r = $this->client->customers()->docs();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    #[Test]
    public function docs_create_returns_field_structure(): void
    {
        $r = $this->client->customers()->docs('create');
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertArrayHasKey('customers', $data, 'docs/create must describe the bulk customers array');
        $this->assertArrayHasKey('structure', $data['customers']);
        $this->assertArrayHasKey('email', $data['customers']['structure']);
        $this->assertArrayHasKey('first_name', $data['customers']['structure']);
    }

    #[Test]
    public function create_single_customer_returns_id_and_outgoing_keys(): void
    {
        $this->requireScope('customers.create');
        $email = $this->uniqueEmail('newcust');

        $r = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'Test', 'last_name' => 'User'],
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertSame(1, $data['total_customers']);
        $this->assertSame(1, $data['new_customers']);
        $this->assertSame(0, $data['failed_customers']);
        $this->assertCount(1, $data['new_customers_data']);

        $row = $data['new_customers_data'][0];
        $this->assertIsInt((int)$row['id']);
        $this->assertGreaterThan(0, (int)$row['id']);
        // first_name is title-cased server-side (see createCustomer
        // transformations) — assert that, not the raw input.
        $this->assertSame('Test', $row['first_name']);
        $this->assertSame($email, $row['email']);
    }

    #[Test]
    public function create_with_duplicate_email_counts_as_existing(): void
    {
        $this->requireScope('customers.create');
        $email = $this->uniqueEmail('dup');

        // First create.
        $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'First', 'last_name' => 'Run'],
        ]);

        // Second create with same email.
        $r = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'Second', 'last_name' => 'Run'],
        ]);
        $data = $r->getData();
        $this->assertSame(0, $data['new_customers'],
            'duplicate email must not create a second row');
        $this->assertSame(1, $data['existing_customers']);
    }

    #[Test]
    public function create_with_unknown_group_id_records_per_row_error(): void
    {
        $this->requireScope('customers.create');
        $email = $this->uniqueEmail('badgroup');

        $r = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'Bad', 'last_name' => 'Group', 'group_id' => 99999999],
        ]);
        $data = $r->getData();
        $this->assertSame(0, $data['new_customers']);
        $this->assertSame(1, $data['failed_customers']);
        $errors = $r->getMeta()['errors'] ?? [];
        $this->assertArrayHasKey($email, $errors);
        $this->assertStringContainsString('custgroupid', $errors[$email]);
    }

    #[Test]
    public function get_by_id_and_by_email_return_same_row(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.view');

        $email = $this->uniqueEmail('roundtrip');
        $created = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'Round', 'last_name' => 'Trip'],
        ])->getData();
        $id = (int)$created['new_customers_data'][0]['id'];

        $byId = $this->client->customers()->get($id)->getData();
        $this->assertSame($id, (int)$byId['id']);
        $this->assertSame($email, $byId['email']);

        $byEmail = $this->client->customers()->get($email)->getData();
        $this->assertSame($id, (int)$byEmail['id']);
        $this->assertSame($email, $byEmail['email']);
    }

    #[Test]
    public function update_by_id_changes_first_name(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.update');

        $email = $this->uniqueEmail('updname');
        $created = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'Before', 'last_name' => 'Update'],
        ])->getData();
        $id = (int)$created['new_customers_data'][0]['id'];

        $r = $this->client->customers()->update(['id' => $id, 'first_name' => 'After']);
        $this->assertSame(200, $r->getStatusCode());
        $data = $r->getData();
        $this->assertSame(1, $data['updated_customers']);

        // Verify via fresh get().
        $back = $this->client->customers()->get($id)->getData();
        $this->assertSame('After', $back['first_name']);
    }

    #[Test]
    public function update_by_email_changes_first_name(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.update');

        $email = $this->uniqueEmail('updemail');
        $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'Before', 'last_name' => 'EmailUpd'],
        ]);

        $r = $this->client->customers()->updateByEmail($email, ['first_name' => 'AfterEmail']);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('AfterEmail', $r->getData()['first_name']);
    }

    // -- addresses -----------------------------------------------------

    private function basicAddressPayload(): array
    {
        return [
            'first_name'         => 'Addr',
            'last_name'          => 'Tester',
            'address_line_1'     => 'Akropoleos',
            'address_line_1_num' => '12A',
            'city'               => 'Athens',
            'state'              => 'Attica',
            'zip'                => '11111',
            'country'            => 'Greece',
            'country_id'         => 1,
            'phone'              => '2100000000',
        ];
    }

    #[Test]
    public function create_customer_with_addresses_persists_them(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.view');

        $email = $this->uniqueEmail('with-addr');
        $r = $this->client->customers()->create([
            [
                'email'      => $email,
                'first_name' => 'Multi',
                'last_name'  => 'Addr',
                'addresses'  => [
                    $this->basicAddressPayload(),
                    array_merge($this->basicAddressPayload(), ['address_line_1' => 'Second Street', 'city' => 'Thessaloniki']),
                ],
            ],
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $id = (int)$r->getData()['new_customers_data'][0]['id'];

        // Re-fetch — addresses on the create response only reflect what got
        // persisted because the controller re-reads via mapOutgoing.
        $back = $this->client->customers()->get($id)->getData();
        $this->assertNotEmpty($back['addresses'] ?? []);
        // Strict count — entity addPosthook used to fire alongside our
        // controller-side address writer, producing duplicates. assertCount
        // pins the one-write-per-address invariant.
        $this->assertCount(2, $back['addresses'],
            'sending two addresses on create must result in exactly two rows');
    }

    #[Test]
    public function add_address_to_existing_customer(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.update');
        $this->requireScope('customers.view');

        $email = $this->uniqueEmail('addaddr');
        $created = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'AddAddr', 'last_name' => 'Test'],
        ])->getData();
        $id = (int)$created['new_customers_data'][0]['id'];

        $r = $this->client->customers()->addAddress($id, $this->basicAddressPayload());
        $this->assertSame(201, $r->getStatusCode());
        $addr = $r->getData();
        $this->assertGreaterThan(0, (int)$addr['id']);
        $this->assertSame('Akropoleos', $addr['address_line_1']);
        $this->assertSame('Athens', $addr['city']);
    }

    #[Test]
    public function update_address_changes_city(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.update');

        $email = $this->uniqueEmail('updaddr');
        $created = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'UpdAddr', 'last_name' => 'Test'],
        ])->getData();
        $id = (int)$created['new_customers_data'][0]['id'];

        $addr = $this->client->customers()->addAddress($id, $this->basicAddressPayload())->getData();
        $addressId = (int)$addr['id'];

        $r = $this->client->customers()->updateAddress($id, $addressId, ['city' => 'Patras']);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('Patras', $r->getData()['city']);
    }

    #[Test]
    public function delete_address_returns_deleted_flag_and_removes_it(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.update');
        $this->requireScope('customers.view');

        $email = $this->uniqueEmail('deladdr');
        $created = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'DelAddr', 'last_name' => 'Test'],
        ])->getData();
        $id = (int)$created['new_customers_data'][0]['id'];

        $addr = $this->client->customers()->addAddress($id, $this->basicAddressPayload())->getData();
        $addressId = (int)$addr['id'];

        $r = $this->client->customers()->deleteAddress($id, $addressId);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($r->getData()['deleted'] ?? false);

        // Confirm the address is no longer in the customer's address book.
        $back = $this->client->customers()->get($id)->getData();
        $remainingIds = array_map(fn($a) => (int)$a['id'], $back['addresses'] ?? []);
        $this->assertNotContains($addressId, $remainingIds);
    }

    #[Test]
    public function add_address_to_unknown_customer_returns_404(): void
    {
        $this->requireScope('customers.update');
        try {
            $this->client->customers()->addAddress(99999999, $this->basicAddressPayload());
            $this->fail('Expected 404 for unknown customer');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function update_address_belonging_to_different_customer_returns_404(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.update');

        // Customer A with one address.
        $emailA = $this->uniqueEmail('crossA');
        $a = $this->client->customers()->create([
            ['email' => $emailA, 'first_name' => 'CrossA', 'last_name' => 'Test'],
        ])->getData();
        $aId = (int)$a['new_customers_data'][0]['id'];
        $addr = $this->client->customers()->addAddress($aId, $this->basicAddressPayload())->getData();
        $addressId = (int)$addr['id'];

        // Customer B.
        $emailB = $this->uniqueEmail('crossB');
        $b = $this->client->customers()->create([
            ['email' => $emailB, 'first_name' => 'CrossB', 'last_name' => 'Test'],
        ])->getData();
        $bId = (int)$b['new_customers_data'][0]['id'];

        // Try to PUT customer A's address via customer B's URL — 404 with
        // "for this customer" detail (prevents cross-tenant editing).
        try {
            $this->client->customers()->updateAddress($bId, $addressId, ['city' => 'Hijacked']);
            $this->fail('Expected 404 when targeting an address that does not belong to the path customer');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function delete_removes_customer(): void
    {
        $this->requireScope('customers.create');
        $this->requireScope('customers.delete');

        $email = $this->uniqueEmail('todelete');
        $created = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'To', 'last_name' => 'Delete'],
        ])->getData();
        $id = (int)$created['new_customers_data'][0]['id'];

        $r = $this->client->customers()->delete($id);
        $this->assertSame(200, $r->getStatusCode());

        // Gone after delete — get() returns the error envelope (status
        // depends on server config, but is consistently not-200).
        try {
            $r = $this->client->customers()->get($id);
            // Some routes return 200 with sendError envelope; in either case
            // the email field on the deleted row should NOT match.
            $data = $r->getData();
            $this->assertNotSame($email, $data['email'] ?? '', 'deleted customer should not still resolve by id');
        } catch (ApiException $e) {
            // 404-style sendError — acceptable.
            $this->assertGreaterThanOrEqual(400, $e->getStatusCode());
        }
    }
}
