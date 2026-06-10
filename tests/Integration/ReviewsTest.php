<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reviews CRUD + product-rating aggregate side effect.
 *
 * Pins:
 *   - Approving a review (status=1) bumps prodratingtotal + prodnumratings
 *   - Demoting back to pending (0) restores them
 *   - Delete restores them
 *   - Pending reviews don't count toward the aggregate
 *   - Cannot reassign a review to a different product on update
 *   - Unknown product_id on create returns 400
 */
final class ReviewsTest extends IntegrationTestCase
{
    private function readRatingsFor(int $productId): array
    {
        $row = $this->client->products()->get($productId)->getData();
        return [
            'total' => (int)($row['prodratingtotal'] ?? 0),
            'num'   => (int)($row['prodnumratings']  ?? 0),
        ];
    }

    #[Test]
    public function pending_review_does_not_count_toward_aggregate(): void
    {
        $this->requireScope('products.write');
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('RevPend'), 'sku' => $this->uid('rev-pend'),
            'price' => 1.0,
        ])->getData()['productid'];
        $before = $this->readRatingsFor($pid);

        // Default status = 0 → pending → must not bump aggregates.
        $rev = $this->client->reviews()->create([
            'product_id' => $pid,
            'rating'     => 5,
            'title'      => 'Excellent',
            'from_name'  => 'sdkit integration',
        ])->getData();
        $this->assertSame(0, (int)$rev['revstatus']);
        $this->assertSame(5, (int)$rev['revrating']);

        $after = $this->readRatingsFor($pid);
        $this->assertSame($before, $after,
            'pending reviews must NOT count toward prodratingtotal / prodnumratings');
    }

    #[Test]
    public function approving_review_bumps_then_unapproving_restores_aggregate(): void
    {
        $this->requireScope('products.write');
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('RevAggr'), 'sku' => $this->uid('rev-aggr'),
            'price' => 1.0,
        ])->getData()['productid'];

        $before = $this->readRatingsFor($pid);

        // Create approved.
        $rev = $this->client->reviews()->create([
            'product_id' => $pid,
            'rating'     => 4,
            'status'     => 1,
        ])->getData();
        $revId = (int)$rev['reviewid'];

        $afterApprove = $this->readRatingsFor($pid);
        $this->assertSame($before['total'] + 4, $afterApprove['total']);
        $this->assertSame($before['num']   + 1, $afterApprove['num']);

        // Demote to pending → aggregate must drop back.
        $this->client->reviews()->update($revId, ['status' => 0]);
        $afterDemote = $this->readRatingsFor($pid);
        $this->assertSame($before, $afterDemote,
            'demoting an approved review back to pending must restore the aggregate');
    }

    #[Test]
    public function delete_approved_review_restores_aggregate(): void
    {
        $this->requireScope('products.write');
        $this->requireScope('products.delete');
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('RevDel'), 'sku' => $this->uid('rev-del'),
            'price' => 1.0,
        ])->getData()['productid'];

        $before = $this->readRatingsFor($pid);
        $rev = $this->client->reviews()->create([
            'product_id' => $pid, 'rating' => 5, 'status' => 1,
        ])->getData();

        $afterCreate = $this->readRatingsFor($pid);
        $this->assertSame($before['total'] + 5, $afterCreate['total']);
        $this->assertSame($before['num']   + 1, $afterCreate['num']);

        $this->client->reviews()->delete((int)$rev['reviewid']);
        $afterDelete = $this->readRatingsFor($pid);
        $this->assertSame($before, $afterDelete);
    }

    #[Test]
    public function list_filters_by_product_and_status(): void
    {
        $this->requireScope('products.write');
        $pid = (int)$this->client->products()->create([
            'name' => $this->uid('RevList'), 'sku' => $this->uid('rev-list'),
            'price' => 1.0,
        ])->getData()['productid'];

        // 2 pending + 1 approved.
        for ($i = 0; $i < 2; $i++) {
            $this->client->reviews()->create(['product_id' => $pid, 'rating' => 3]);
        }
        $this->client->reviews()->create(['product_id' => $pid, 'rating' => 5, 'status' => 1]);

        $pending = $this->client->reviews()->list(['product_id' => $pid, 'status' => 0])->getData();
        $approved = $this->client->reviews()->list(['product_id' => $pid, 'status' => 1])->getData();
        $this->assertCount(2, $pending);
        $this->assertCount(1, $approved);
        foreach ($pending  as $r) { $this->assertSame(0, (int)$r['revstatus']); }
        foreach ($approved as $r) { $this->assertSame(1, (int)$r['revstatus']); }
    }

    #[Test]
    public function create_for_unknown_product_returns_400(): void
    {
        $this->requireScope('products.write');
        try {
            $this->client->reviews()->create([
                'product_id' => 99999999,
                'rating'     => 5,
            ]);
            $this->fail('Expected 400 for unknown product_id');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    #[Test]
    public function update_cannot_move_review_to_different_product(): void
    {
        $this->requireScope('products.write');
        $pA = (int)$this->client->products()->create([
            'name' => $this->uid('RevA'), 'sku' => $this->uid('rev-a'), 'price' => 1.0,
        ])->getData()['productid'];
        $pB = (int)$this->client->products()->create([
            'name' => $this->uid('RevB'), 'sku' => $this->uid('rev-b'), 'price' => 1.0,
        ])->getData()['productid'];

        $rev = $this->client->reviews()->create([
            'product_id' => $pA, 'rating' => 5, 'status' => 1,
        ])->getData();

        // Attempt to reassign to product B — server must silently strip
        // the change (revproductid is not in the writable patch).
        $this->client->reviews()->update((int)$rev['reviewid'], [
            'product_id' => $pB,
            'title'      => 'attempted reassignment',
        ]);

        $back = $this->client->reviews()->get((int)$rev['reviewid'])->getData();
        $this->assertSame($pA, (int)$back['revproductid'],
            'partner update must not move a review to a different product (would corrupt both aggregates)');
        $this->assertSame('attempted reassignment', $back['revtitle']);
    }
}
