<?php

declare(strict_types=1);

use NagaCommerce\SDK\Resources\Products;
use NagaCommerce\SDK\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pin the URL / method / body contract for every Products resource method.
 *
 * The previous SDK shipped wrong paths (e.g. `/products/{id}` when the
 * server route is `/products/product/{id}`) and silently 404'd in
 * production. These tests are the regression guard so a future refactor
 * has to touch BOTH the SDK and the failing test before paths drift again.
 */
final class ProductsTest extends TestCase
{
    private RecordingHttpClient $http;
    private Products $products;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->products = new Products($this->http);
    }

    private function lastReq(): array
    {
        return $this->http->lastRequest();
    }

    #[Test]
    public function count_hits_products_count(): void
    {
        $this->products->count();
        $req = $this->lastReq();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/products/count', $req['path']);
    }

    #[Test]
    public function updated_since_includes_timestamp_in_path(): void
    {
        $this->products->updatedSince(1717200000, ['limit' => 100]);
        $req = $this->lastReq();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/products/updated-since/1717200000', $req['path']);
        $this->assertSame(['limit' => 100], $req['query']);
    }

    #[Test]
    public function get_uses_product_subpath_not_bare_id(): void
    {
        // Regression guard: the previous SDK called /products/{id} which the
        // server doesn't expose — it 404'd every read in production.
        $this->products->get(123);
        $this->assertSame('/products/product/123', $this->lastReq()['path']);
    }

    #[Test]
    public function search_calls_products_find_with_query_params(): void
    {
        $this->products->search(['search_query' => 'bag', 'limit' => 20]);
        $req = $this->lastReq();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/products/find', $req['path']);
        $this->assertSame(['search_query' => 'bag', 'limit' => 20], $req['query']);
    }

    #[Test]
    public function create_posts_to_products_create(): void
    {
        $payload = ['name' => 'X', 'sku' => 'X-1', 'price' => 9.9];
        $this->products->create($payload);
        $req = $this->lastReq();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/products/create', $req['path']);
        $this->assertSame($payload, $req['body']);
    }

    #[Test]
    public function update_puts_to_product_subpath(): void
    {
        $this->products->update(123, ['price' => 7.9]);
        $req = $this->lastReq();
        $this->assertSame('PUT', $req['method']);
        $this->assertSame('/products/product/123', $req['path']);
    }

    #[Test]
    public function delete_issues_DELETE_on_product_subpath(): void
    {
        $this->products->delete(123);
        $req = $this->lastReq();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame('/products/product/123', $req['path']);
    }

    // -- batch --------------------------------------------------------------

    #[Test]
    public function batch_create_posts_products_array(): void
    {
        $rows = [['name' => 'A'], ['name' => 'B']];
        $this->products->batchCreate($rows);
        $req = $this->lastReq();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/products/batch/create', $req['path']);
        $this->assertSame(['products' => $rows], $req['body']);
    }

    #[Test]
    public function batch_update_posts_products_array(): void
    {
        $rows = [['id' => 1, 'price' => 9.9], ['id' => 2, 'visible' => 0]];
        $this->products->batchUpdate($rows);
        $req = $this->lastReq();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/products/batch/update', $req['path']);
        $this->assertSame(['products' => $rows], $req['body']);
    }

    #[Test]
    public function batch_delete_posts_product_ids_array(): void
    {
        $this->products->batchDelete([10, 20, 30]);
        $req = $this->lastReq();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/products/batch/delete', $req['path']);
        $this->assertSame(['product_ids' => [10, 20, 30]], $req['body']);
    }

    #[Test]
    public function adjust_stock_posts_products_array_to_stock_changed(): void
    {
        $this->products->adjustStock(
            [['product_id' => 100, 'delta' => -2]],
            ['source' => 'wms']
        );
        $req = $this->lastReq();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/products/inventory/stock-changed', $req['path']);
        $this->assertSame('wms', $req['body']['source']);
        $this->assertSame([['product_id' => 100, 'delta' => -2]], $req['body']['products']);
    }

    #[Test]
    public function set_stock_posts_quantity_to_set_stock(): void
    {
        $this->products->setStock([['product_id' => 100, 'quantity' => 50]]);
        $req = $this->lastReq();
        $this->assertSame('/products/inventory/set-stock', $req['path']);
        $this->assertSame(50, $req['body']['products'][0]['quantity']);
    }

    // -- per-product custom field assignments -----------------------------------

    #[Test]
    public function get_custom_fields_hits_per_product_subpath(): void
    {
        $this->products->getCustomFields(123);
        $req = $this->lastReq();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/products/product/123/custom-fields', $req['path']);
    }

    #[Test]
    public function replace_custom_fields_PUTs_payload_under_custom_fields_key(): void
    {
        $this->products->replaceCustomFields(123, [['label' => 'Color', 'values' => ['Red']]]);
        $req = $this->lastReq();
        $this->assertSame('PUT', $req['method']);
        $this->assertSame('/products/product/123/custom-fields', $req['path']);
        $this->assertSame(
            [['label' => 'Color', 'values' => ['Red']]],
            $req['body']['custom_fields']
        );
    }

    #[Test]
    public function append_custom_fields_POSTs_to_same_path(): void
    {
        $this->products->appendCustomFields(123, [['label_id' => 7, 'value_ids' => [12]]]);
        $req = $this->lastReq();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/products/product/123/custom-fields', $req['path']);
    }

    #[Test]
    public function remove_single_custom_field_sends_label_and_value_in_query(): void
    {
        // Query params, not body — server reads $_GET['label_id'] / $_GET['value_id'].
        $this->products->removeCustomField(123, 7, 12);
        $req = $this->lastReq();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame('/products/product/123/custom-fields', $req['path']);
        $this->assertSame(7, $req['query']['label_id']);
        $this->assertSame(12, $req['query']['value_id']);
    }

    #[Test]
    public function clear_custom_fields_uses_all_query_param(): void
    {
        $this->products->clearCustomFields(123);
        $req = $this->lastReq();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame(['all' => 1], $req['query']);
    }

    // -- store-wide definitions --------------------------------------------------

    #[Test]
    public function list_definitions_hits_custom_fields_root(): void
    {
        $this->products->listCustomFieldDefinitions();
        $req = $this->lastReq();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/products/custom-fields', $req['path']);
    }

    #[Test]
    public function create_label_posts_name_plus_options(): void
    {
        $this->products->createCustomFieldLabel('Color', ['visible' => 1, 'sort_order' => 5]);
        $req = $this->lastReq();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/products/custom-fields/labels', $req['path']);
        $this->assertSame('Color', $req['body']['label_name']);
        $this->assertSame(1, $req['body']['visible']);
        $this->assertSame(5, $req['body']['sort_order']);
    }

    #[Test]
    public function update_label_PUTs_patch_to_labels_subpath(): void
    {
        $this->products->updateCustomFieldLabel(7, ['label_name' => 'Colour']);
        $req = $this->lastReq();
        $this->assertSame('PUT', $req['method']);
        $this->assertSame('/products/custom-fields/labels/7', $req['path']);
        $this->assertSame(['label_name' => 'Colour'], $req['body']);
    }

    #[Test]
    public function delete_label_DELETEs_labels_subpath(): void
    {
        $this->products->deleteCustomFieldLabel(7);
        $req = $this->lastReq();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame('/products/custom-fields/labels/7', $req['path']);
    }

    #[Test]
    public function create_value_posts_under_label_values_subpath(): void
    {
        $this->products->createCustomFieldValue(7, 'Forest Green', ['sort_order' => 2]);
        $req = $this->lastReq();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/products/custom-fields/labels/7/values', $req['path']);
        $this->assertSame('Forest Green', $req['body']['value']);
        $this->assertSame(2, $req['body']['sort_order']);
    }

    #[Test]
    public function update_value_PUTs_patch_to_values_subpath(): void
    {
        $this->products->updateCustomFieldValue(12, ['value' => 'Lime']);
        $req = $this->lastReq();
        $this->assertSame('PUT', $req['method']);
        $this->assertSame('/products/custom-fields/values/12', $req['path']);
    }

    #[Test]
    public function delete_value_DELETEs_values_subpath(): void
    {
        $this->products->deleteCustomFieldValue(12);
        $req = $this->lastReq();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame('/products/custom-fields/values/12', $req['path']);
    }
}
