<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Customer groups CRUD.
 *
 * Pins:
 *   - Create with friendly keys persists to customer_groups.
 *   - `is_default: true` demotes the previous default (entity addPosthook).
 *   - `category_access: 'specific'` + `access_category_ids` round-trips.
 *   - Update is partial-friendly.
 *   - Delete of the DEFAULT group is rejected with 409.
 *   - Delete of a non-default group zeroes any customer's custgroupid
 *     that was pointing at it (storefront fallback to default).
 */
final class CustomerGroupsTest extends IntegrationTestCase
{
    #[Test]
    public function list_returns_array(): void
    {
        // Not all stores carry a default group on the dev DB — some
        // legacy installs left every row's isdefault=0 (the storefront
        // treats custgroupid=0 as "no group" instead). The list endpoint
        // just round-trips whatever is there.
        $this->requireScope('customers.view');
        $r = $this->client->customers()->listGroups();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertIsArray($r->getData());
    }

    #[Test]
    public function create_minimal_group_persists(): void
    {
        $this->requireScope('customers.update');
        $name = 'sdkit-grp-' . $this->uid('m');
        $r = $this->client->customers()->createGroup(['name' => $name]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertSame($name, $row['groupname']);
        // Defaults the controller fills in.
        $this->assertSame('percent', $row['discountmethod']);
        $this->assertSame('all', $row['categoryaccesstype']);
        $this->assertSame(0, (int)$row['isdefault']);
    }

    #[Test]
    public function create_with_is_default_demotes_other_defaults(): void
    {
        // Entity addPosthook (entity.customergroup.php:48-55) zeros all
        // other rows' isdefault when this one is flagged. Pin that side
        // effect end-to-end: create A as default, then create B as
        // default → A must be demoted.
        $this->requireScope('customers.update');
        $this->requireScope('customers.view');

        $a = $this->client->customers()->createGroup([
            'name'       => 'sdkit-defA-' . $this->uid('a'),
            'is_default' => true,
        ])->getData();
        $aId = (int)$a['customergroupid'];
        $this->assertSame(1, (int)$a['isdefault']);

        $b = $this->client->customers()->createGroup([
            'name'       => 'sdkit-defB-' . $this->uid('b'),
            'is_default' => true,
        ])->getData();
        $this->assertSame(1, (int)$b['isdefault']);

        // A must now be demoted.
        $aBack = $this->client->customers()->getGroup($aId)->getData();
        $this->assertSame(0, (int)$aBack['isdefault'],
            'previous default must be demoted when another group is flagged default');
    }

    #[Test]
    public function create_with_specific_category_access_persists_access_list(): void
    {
        $this->requireScope('customers.update');
        $this->requireScope('customers.view');
        $this->requireScope('categories.read');

        // Pick any two existing categories for the access list.
        $cats = $this->client->categories()->list()->getData();
        if (count($cats) < 2) {
            $this->markTestSkipped('store has fewer than 2 categories — cannot exercise specific-access wiring');
        }
        $c1 = (int)$cats[0]['categoryid'];
        $c2 = (int)$cats[1]['categoryid'];

        $created = $this->client->customers()->createGroup([
            'name'                  => 'sdkit-cat-' . $this->uid('c'),
            'category_access'       => 'specific',
            'access_category_ids'   => [$c1, $c2],
        ])->getData();
        $this->assertSame('specific', $created['categoryaccesstype']);

        // Re-fetch via GET — fetchGroup hydrates the access list when type=specific.
        $back = $this->client->customers()->getGroup((int)$created['customergroupid'])->getData();
        $this->assertNotEmpty($back['access_category_ids'] ?? []);
        sort($back['access_category_ids']);
        $expected = [$c1, $c2]; sort($expected);
        $this->assertSame($expected, array_map('intval', $back['access_category_ids']));
    }

    #[Test]
    public function partial_update_changes_only_sent_fields(): void
    {
        $this->requireScope('customers.update');
        $created = $this->client->customers()->createGroup([
            'name'            => 'sdkit-upd-' . $this->uid('u'),
            'discount'        => 5.00,
            'discount_method' => 'percent',
        ])->getData();
        $groupId = (int)$created['customergroupid'];

        $r = $this->client->customers()->updateGroup($groupId, ['discount' => 12.50]);
        $this->assertSame(200, $r->getStatusCode());
        $back = $r->getData();
        $this->assertEqualsWithDelta(12.50, (float)$back['discount'], 0.001);
        // Method should NOT be touched by a discount-only patch.
        $this->assertSame('percent', $back['discountmethod']);
    }

    #[Test]
    public function delete_default_group_returns_409(): void
    {
        // Create our own default so we don't depend on the store seed
        // (some dev installs have isdefault=0 across the board). Then
        // try to delete it — must 409.
        $this->requireScope('customers.update');
        $this->requireScope('customers.delete');

        $g = $this->client->customers()->createGroup([
            'name'       => 'sdkit-locked-' . $this->uid('l'),
            'is_default' => true,
        ])->getData();
        $groupId = (int)$g['customergroupid'];

        try {
            $this->client->customers()->deleteGroup($groupId);
            $this->fail('Default group must not be deletable');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('default', strtolower($e->getErrorDetail()));
        } finally {
            // Demote so the rest of the suite isn't stuck with our test
            // default and the cleanup teardown can proceed.
            $this->client->customers()->updateGroup($groupId, ['is_default' => false]);
            $this->client->customers()->deleteGroup($groupId);
        }
    }

    #[Test]
    public function delete_zeroes_customers_pointing_at_the_group(): void
    {
        // Side effect contract: customer rows that were assigned this
        // group fall back to "default" (custgroupid = 0) after delete,
        // instead of dangling a dead FK.
        $this->requireScope('customers.create');
        $this->requireScope('customers.update');
        $this->requireScope('customers.delete');
        $this->requireScope('customers.view');

        $grp = $this->client->customers()->createGroup([
            'name' => 'sdkit-del-' . $this->uid('d'),
        ])->getData();
        $groupId = (int)$grp['customergroupid'];

        $email = strtolower($this->uid('grpcust')) . '@sdk-integration.test';
        $cust = $this->client->customers()->create([
            ['email' => $email, 'first_name' => 'G', 'last_name' => 'Tester', 'group_id' => $groupId],
        ])->getData();
        $custId = (int)$cust['new_customers_data'][0]['id'];
        $this->assertSame($groupId, (int)$cust['new_customers_data'][0]['group_id']);

        $this->client->customers()->deleteGroup($groupId);

        $back = $this->client->customers()->get($custId)->getData();
        $this->assertSame(0, (int)$back['group_id'],
            'customer must fall back to default group (0) after their assigned group is deleted');
    }

    #[Test]
    public function get_unknown_group_returns_404(): void
    {
        $this->requireScope('customers.view');
        try {
            $this->client->customers()->getGroup(99999999);
            $this->fail('Expected 404 for unknown group');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
