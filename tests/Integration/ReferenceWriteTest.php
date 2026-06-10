<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /reference write surface — currencies, tax classes, availabilities.
 *
 * Pins:
 *   - Currency `is_default: true` demotes any prior default in the same
 *     transaction (storefront price-conversion only honors one default).
 *   - Deleting the default currency returns 409.
 *   - Deleting a tax class zeroes any product's tax_class_id pointing at it.
 *   - Deleting an availability zeroes any product's prodavailability ditto.
 *   - Newly-created availability id is immediately usable as a
 *     product's `availability_id` (FK validation closes the loop).
 */
final class ReferenceWriteTest extends IntegrationTestCase
{
    #[Test]
    public function create_currency_with_minimal_payload(): void
    {
        $this->requireScope('reference.write');
        $code = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
        $r = $this->client->reference()->createCurrency([
            'code'             => $code,
            'name'             => 'sdkit-curr-' . $this->uid('c'),
            'symbol'           => '#',
            'exchange_rate'    => 1.25,
            'decimal_places'   => 2,
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $this->assertSame($code, $row['currencycode']);
        $this->assertEqualsWithDelta(1.25, (float)$row['currencyexchangerate'], 0.0001);
        $this->assertSame(0, (int)$row['currencyisdefault']);
    }

    #[Test]
    public function promoting_currency_default_demotes_previous(): void
    {
        $this->requireScope('reference.read');
        $this->requireScope('reference.write');

        $existing = $this->client->reference()->currencies()->getData();
        $priorDefault = array_values(array_filter($existing, fn($c) => !empty($c['is_default'])));
        $priorDefaultId = $priorDefault ? (int)$priorDefault[0]['id'] : null;

        $code = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
        $new = $this->client->reference()->createCurrency([
            'code'       => $code,
            'name'       => 'sdkit-newdef-' . $this->uid('d'),
            'is_default' => true,
        ])->getData();
        $newId = (int)$new['currencyid'];
        $this->assertSame(1, (int)$new['currencyisdefault']);

        if ($priorDefaultId !== null) {
            // Old default must now be demoted. Refetch via list.
            $after = $this->client->reference()->currencies()->getData();
            $found = array_values(array_filter($after, fn($c) => (int)$c['id'] === $priorDefaultId));
            $this->assertNotEmpty($found);
            $this->assertFalse((bool)$found[0]['is_default'],
                'previous default must be demoted when a new currency is flagged default');

            // Restore for idempotency.
            $this->client->reference()->updateCurrency($priorDefaultId, ['is_default' => true]);
        } else {
            // Demote our test-default so we don't break subsequent runs.
            $this->client->reference()->updateCurrency($newId, ['is_default' => false]);
        }
    }

    #[Test]
    public function delete_default_currency_returns_409(): void
    {
        $this->requireScope('reference.write');
        $this->requireScope('reference.delete');

        $code = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
        $cur = $this->client->reference()->createCurrency([
            'code'       => $code,
            'name'       => 'sdkit-locked-' . $this->uid('l'),
            'is_default' => true,
        ])->getData();
        $id = (int)$cur['currencyid'];

        try {
            $this->client->reference()->deleteCurrency($id);
            $this->fail('Default currency must not be deletable');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        } finally {
            $this->client->reference()->updateCurrency($id, ['is_default' => false]);
            $this->client->reference()->deleteCurrency($id);
        }
    }

    #[Test]
    public function create_tax_class_then_delete_it_zeroes_referencing_products(): void
    {
        $this->requireScope('reference.write');
        $this->requireScope('reference.delete');
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $tc = $this->client->reference()->createTaxClass(['name' => 'sdkit-tax-' . $this->uid('t')])->getData();
        $tcId = (int)$tc['id'];

        // Point a freshly-created product at it.
        $pid = (int)$this->client->products()->create([
            'name'         => $this->uid('TaxedProd'),
            'sku'          => $this->uid('tax-prod'),
            'price'        => 10.00,
            'tax_class_id' => $tcId,
        ])->getData()['productid'];
        $this->assertSame($tcId, (int)$this->client->products()->get($pid)->getData()['tax_class_id']);

        $this->client->reference()->deleteTaxClass($tcId);

        // Product's tax_class_id must now be 0 (storefront fallback).
        $this->assertSame(0, (int)$this->client->products()->get($pid)->getData()['tax_class_id'],
            'deleting a tax class must zero referencing products, not dangle the FK');
    }

    #[Test]
    public function availability_round_trip_immediately_usable_as_product_availability_id(): void
    {
        // The most useful invariant for partners: a value created here
        // is valid as a product `availability_id` on the very next call.
        $this->requireScope('reference.write');
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $av = $this->client->reference()->createAvailability([
            'title'      => 'sdkit-avail-' . $this->uid('a'),
            'color'      => 'in-stock',
            'sort_order' => 99,
        ])->getData();
        $availId = (int)$av['availid'];

        $pid = (int)$this->client->products()->create([
            'name'            => $this->uid('AvailProd'),
            'sku'             => $this->uid('avail-prod'),
            'price'           => 1.0,
            'availability_id' => $availId,
        ])->getData()['productid'];
        $back = $this->client->products()->get($pid)->getData();
        $this->assertSame($availId, (int)$back['prodavailability']);
    }

    #[Test]
    public function delete_availability_zeroes_referencing_products(): void
    {
        $this->requireScope('reference.write');
        $this->requireScope('reference.delete');
        $this->requireScope('products.write');
        $this->requireScope('products.read');

        $av = $this->client->reference()->createAvailability([
            'title' => 'sdkit-availdel-' . $this->uid('d'),
        ])->getData();
        $availId = (int)$av['availid'];

        $pid = (int)$this->client->products()->create([
            'name'            => $this->uid('AvailDelProd'),
            'sku'             => $this->uid('avail-del'),
            'price'           => 1.0,
            'availability_id' => $availId,
        ])->getData()['productid'];

        $this->client->reference()->deleteAvailability($availId);

        $back = $this->client->products()->get($pid)->getData();
        $this->assertSame(0, (int)$back['prodavailability']);
    }
}
