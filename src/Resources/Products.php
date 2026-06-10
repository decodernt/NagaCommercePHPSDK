<?php

namespace NagaCommerce\SDK\Resources;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Http\Response;

/**
 * Products resource — exposes /api/products endpoints.
 *
 * URL conventions match the server: a single product is addressed as
 * `/products/product/{id}` (not `/products/{id}`). The previous version of
 * this SDK shipped wrong paths and silently 404'd on the server.
 */
class Products
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Total product count. Scope: products.read
     */
    public function count(): Response
    {
        return $this->http->get('/products/count');
    }

    /**
     * Products modified since a UNIX timestamp. Useful for incremental sync.
     * Scope: products.read
     *
     * @param int   $sinceTimestamp UNIX seconds; rows with prodlastmodified >= ts
     * @param array $params         start (int), limit (int, max 500)
     */
    public function updatedSince(int $sinceTimestamp, array $params = []): Response
    {
        return $this->http->get('/products/updated-since/' . $sinceTimestamp, $params);
    }

    /**
     * Get a single product (with PRODUCTSPANEL pricing + assigned custom_fields).
     * Scope: products.read
     */
    public function get(int $id): Response
    {
        return $this->http->get('/products/product/' . $id);
    }

    /**
     * Search products. Scope: products.read
     *
     * @param array $params Supported keys:
     *   - search_query (string)
     *   - category_ids (array|string CSV)
     *   - brand_ids (array|string CSV)
     *   - sort (string: name / name_desc / id / id_desc / date / date_desc /
     *           priceasc / pricedesc / featured / bestselling / relevance / newest)
     *   - start (int), limit (int)
     */
    public function search(array $params = []): Response
    {
        return $this->http->get('/products/find', $params);
    }

    /**
     * Create a product. Scope: products.write
     *
     * Friendly field names map to the underlying entity columns. See README
     * for the full table. Extra non-friendly columns (anything starting with
     * `prod`, plus a small allowlist) pass through unchanged.
     *
     * @param array $data Product fields, plus optionally:
     *   - categories  (int[])             — assigned to the new product
     *   - custom_fields (array)           — see Products::CUSTOM_FIELDS_SHAPE
     *   - images (array)                  — see Products::IMAGES_SHAPE.
     *                                       Server downloads each URL via
     *                                       MEDIAMANAGER (deduped by URL +
     *                                       content hash), then attaches
     *                                       them to the product.
     */
    public function create(array $data): Response
    {
        return $this->http->post('/products/create', $data);
    }

    /**
     * Update an existing product. Scope: products.write
     *
     * Sending the `categories` or `custom_fields` keys REPLACES the existing
     * values; omit them to leave existing assignments alone. Other fields
     * follow PATCH-like semantics — only the keys you send are written.
     */
    public function update(int $id, array $data): Response
    {
        return $this->http->put('/products/product/' . $id, $data);
    }

    /**
     * Delete a product and every dependent row across 13 satellite tables.
     * Refuses (409) when the product has order line items. Scope: products.delete
     */
    public function delete(int $id): Response
    {
        return $this->http->delete('/products/product/' . $id);
    }

    // ---------------------------------------------------------------------
    // Batch CRUD
    // ---------------------------------------------------------------------
    //
    // All three batch endpoints cap at 500 rows per call (server enforced).
    // Create/update use partial-success semantics — each row succeeds or
    // fails independently and the response carries per-row `results`. Delete
    // is all-or-nothing on the cascade transaction, but it skips (not fails)
    // products that have order line items, matching admin behavior.

    /**
     * Batch create. Scope: products.write
     *
     * @param array $rows  Up to 500 product payloads. Each row takes the
     *                     same fields as create() (friendly names + raw
     *                     prod* columns + categories + custom_fields).
     * @return Response    data: { results, created, failed, total }
     *                     results: [ { index, success, product_id|error, status? } ]
     */
    public function batchCreate(array $rows): Response
    {
        return $this->http->post('/products/batch/create', ['products' => $rows]);
    }

    /**
     * Batch update. Scope: products.write
     *
     * Each row MUST include `id`. Rows without `id` or referencing a
     * non-existent product are returned as failures but don't abort the
     * batch.
     *
     * @param array $rows  Up to 500. Each row: { id, ...fields-to-update }
     * @return Response    data: { results, updated, failed, total }
     */
    public function batchUpdate(array $rows): Response
    {
        return $this->http->post('/products/batch/update', ['products' => $rows]);
    }

    /**
     * Batch delete. Scope: products.delete
     *
     * Products that have order line items are SKIPPED (returned in
     * `skipped_has_orders`), not failed. Non-existent ids are returned in
     * `not_found`. Everything else cascade-deletes in one transaction.
     *
     * @param array $productIds Up to 500 ids
     * @return Response  data: { deleted, skipped_has_orders, not_found, requested }
     */
    public function batchDelete(array $productIds): Response
    {
        return $this->http->post('/products/batch/delete', ['product_ids' => $productIds]);
    }

    // ---------------------------------------------------------------------
    // Inventory
    // ---------------------------------------------------------------------

    /**
     * Apply stock deltas. Each entry: product_id (or variation_id), delta (int).
     * Scope: products.write
     *
     * @param array $products [{ product_id, variation_id, delta }, ...]
     * @param array $context  Optional { source, reason } recorded on the audit ledger
     */
    public function adjustStock(array $products, array $context = []): Response
    {
        $payload = array_merge($context, ['products' => $products]);
        return $this->http->post('/products/inventory/stock-changed', $payload);
    }

    /**
     * Set absolute stock quantities. Same envelope as adjustStock(), but each
     * entry takes `quantity` instead of `delta`. Scope: products.write
     *
     * @param array $products [{ product_id, variation_id, quantity }, ...]
     * @param array $context  Optional { source, reason }
     */
    public function setStock(array $products, array $context = []): Response
    {
        $payload = array_merge($context, ['products' => $products]);
        return $this->http->post('/products/inventory/set-stock', $payload);
    }

    // ---------------------------------------------------------------------
    // Per-product custom field assignments
    // ---------------------------------------------------------------------

    /**
     * List the custom field labels + values currently assigned to a product.
     * Scope: products.read
     */
    public function getCustomFields(int $productId): Response
    {
        return $this->http->get('/products/product/' . $productId . '/custom-fields');
    }

    /**
     * Replace a product's custom field assignments (clear then add).
     * Scope: products.write
     *
     * @param int   $productId
     * @param array $customFields See CUSTOM_FIELDS_SHAPE
     */
    public function replaceCustomFields(int $productId, array $customFields): Response
    {
        return $this->http->put(
            '/products/product/' . $productId . '/custom-fields',
            ['custom_fields' => $customFields]
        );
    }

    /**
     * Append assignments without removing existing ones. Idempotent — server
     * silently skips duplicates on the (product, label, value) unique key.
     * Scope: products.write
     */
    public function appendCustomFields(int $productId, array $customFields): Response
    {
        return $this->http->post(
            '/products/product/' . $productId . '/custom-fields',
            ['custom_fields' => $customFields]
        );
    }

    /**
     * Remove a single (label, value) pair from a product. Scope: products.write
     */
    public function removeCustomField(int $productId, int $labelId, int $valueId): Response
    {
        return $this->http->delete(
            '/products/product/' . $productId . '/custom-fields',
            ['label_id' => $labelId, 'value_id' => $valueId]
        );
    }

    /**
     * Clear every custom field assignment for a product. Scope: products.write
     */
    public function clearCustomFields(int $productId): Response
    {
        return $this->http->delete(
            '/products/product/' . $productId . '/custom-fields',
            ['all' => 1]
        );
    }

    // ---------------------------------------------------------------------
    // Store-wide custom field definitions (labels + values)
    // ---------------------------------------------------------------------

    /**
     * List every defined custom field label with its values. Scope: products.read
     */
    public function listCustomFieldDefinitions(): Response
    {
        return $this->http->get('/products/custom-fields');
    }

    /**
     * Create (or find by name) a custom field label. Idempotent.
     * Scope: products.write
     *
     * @param string $name
     * @param array  $options visible (bool|int), sort_order (int)
     */
    public function createCustomFieldLabel(string $name, array $options = []): Response
    {
        return $this->http->post(
            '/products/custom-fields/labels',
            array_merge(['label_name' => $name], $options)
        );
    }

    /**
     * Rename / re-sort / hide a label. Scope: products.write
     */
    public function updateCustomFieldLabel(int $labelId, array $patch): Response
    {
        return $this->http->put('/products/custom-fields/labels/' . $labelId, $patch);
    }

    /**
     * Delete a label. FK cascades remove its values + assignments.
     * Scope: products.write
     */
    public function deleteCustomFieldLabel(int $labelId): Response
    {
        return $this->http->delete('/products/custom-fields/labels/' . $labelId);
    }

    /**
     * Add a value to a label (or return the existing row if the value text
     * already exists under that label). Scope: products.write
     *
     * @param int    $labelId
     * @param string $value
     * @param array  $options slug (string), visible (bool|int), sort_order (int), image_id (int)
     */
    public function createCustomFieldValue(int $labelId, string $value, array $options = []): Response
    {
        return $this->http->post(
            '/products/custom-fields/labels/' . $labelId . '/values',
            array_merge(['value' => $value], $options)
        );
    }

    /**
     * Update a value (re-renames the slug when `value` changes unless `slug`
     * is also provided). Scope: products.write
     */
    public function updateCustomFieldValue(int $valueId, array $patch): Response
    {
        return $this->http->put('/products/custom-fields/values/' . $valueId, $patch);
    }

    public function deleteCustomFieldValue(int $valueId): Response
    {
        return $this->http->delete('/products/custom-fields/values/' . $valueId);
    }

    // ---------------------------------------------------------------------
    // Variations: options, option values, option sets, combinations
    // ---------------------------------------------------------------------

    /**
     * List every product option (Size, Color, …) in the store.
     * Scope: products.read.
     */
    public function listOptions(): Response
    {
        return $this->http->get('/products/options');
    }

    /**
     * Create a store-wide product option. Required: `name`. Optional:
     * `display_name`, `required`, `is_color`, `is_size`. Scope: products.write.
     */
    public function createOption(array $data): Response
    {
        return $this->http->post('/products/options', $data);
    }

    public function updateOption(int $optionId, array $data): Response
    {
        return $this->http->put('/products/options/' . $optionId, $data);
    }

    public function deleteOption(int $optionId): Response
    {
        return $this->http->delete('/products/options/' . $optionId);
    }

    /**
     * List the values configured for a given option.
     */
    public function listOptionValues(int $optionId): Response
    {
        return $this->http->get('/products/options/' . $optionId . '/values');
    }

    /**
     * Add a value to an option. Required: `value`. Optional: `sort_order`,
     * `is_default`, `extras` (color/pattern metadata — same shape admin uses).
     */
    public function createOptionValue(int $optionId, array $data): Response
    {
        return $this->http->post('/products/options/' . $optionId . '/values', $data);
    }

    public function updateOptionValue(int $valueId, array $data): Response
    {
        return $this->http->put('/products/option-values/' . $valueId, $data);
    }

    public function deleteOptionValue(int $valueId): Response
    {
        return $this->http->delete('/products/option-values/' . $valueId);
    }

    /**
     * List option sets with their constituent options eagerly loaded.
     */
    public function listOptionSets(): Response
    {
        return $this->http->get('/products/option-sets');
    }

    /**
     * Create an option set. Required: `name`. Optional: `option_ids` (int[])
     * to populate the set in one shot.
     */
    public function createOptionSet(array $data): Response
    {
        return $this->http->post('/products/option-sets', $data);
    }

    public function getOptionSet(int $setId): Response
    {
        return $this->http->get('/products/option-sets/' . $setId);
    }

    public function updateOptionSet(int $setId, array $data): Response
    {
        return $this->http->put('/products/option-sets/' . $setId, $data);
    }

    /**
     * Delete an option set. Side effects: every product currently pointing
     * at this set has its `prodoptionsetid` zeroed; the set's combinations
     * (product_variation_combinations rows) are removed.
     */
    public function deleteOptionSet(int $setId): Response
    {
        return $this->http->delete('/products/option-sets/' . $setId);
    }

    /**
     * Add an option to an existing set. Idempotent — re-adding the same
     * option returns the set with `meta.added = false`.
     */
    public function addOptionToSet(int $setId, int $optionId): Response
    {
        return $this->http->post('/products/option-sets/' . $setId . '/options', ['option_id' => $optionId]);
    }

    public function removeOptionFromSet(int $setId, int $optionId): Response
    {
        return $this->http->delete('/products/option-sets/' . $setId . '/options/' . $optionId);
    }

    /**
     * List a product's variation combinations.
     */
    public function listVariations(int $productId): Response
    {
        return $this->http->get('/products/product/' . $productId . '/variations');
    }

    /**
     * Create a variation (combination) for a product. Required: `value_ids`
     * (int[] of product_option_values.valueid).
     *
     * Pricing: send `price` (and optionally `sale_price` + `sale_price_from`
     * / `sale_price_to` as ISO 8601 strings or unix ints). The server
     * derives the internal `vcpricediff` modifier automatically — partners
     * don't need to set it (and the legacy `add`/`subtract` enum values
     * the app never actually consumed are not exposed).
     *
     * Other commonly-used fields: `sku`, `mpn`, `barcode`, `stock`,
     * `low_stock`, `enabled`, `weight`, `width`, `height`, `depth`,
     * `allow_backorders`, `max_backorder_quantity`, `discard_discounts`,
     * `is_default` (storefront preselect — server enforces single-default
     * per product), `customer_group_ids` (int[] or CSV string),
     * `image_url` (string or {url, alt}; resolved via MEDIAMANAGER + dedupe),
     * `metadata`, and the product-wiring shortcut `option_set_id` (sets
     * prodoptionsetid in the same call when not already pinned).
     */
    public function createVariation(int $productId, array $data): Response
    {
        return $this->http->post('/products/product/' . $productId . '/variations', $data);
    }

    public function updateVariation(int $productId, int $combinationId, array $data): Response
    {
        return $this->http->put('/products/product/' . $productId . '/variations/' . $combinationId, $data);
    }

    public function deleteVariation(int $productId, int $combinationId): Response
    {
        return $this->http->delete('/products/product/' . $productId . '/variations/' . $combinationId);
    }

    // ---------------------------------------------------------------------
    // Per-product image gallery (single-row CRUD)
    //
    // The `images` field on create/update REPLACES the whole gallery;
    // these endpoints let partners manage one row at a time. Useful for
    // photographer workflows where new shots get added as they come back
    // from editing rather than as a complete re-upload.
    // ---------------------------------------------------------------------

    /** List a product's image rows (imageid, imagefile, imageisthumb, …). */
    public function listImages(int $productId): Response
    {
        return $this->http->get('/products/product/' . $productId . '/images');
    }

    /**
     * Append a single image to a product's gallery without disturbing
     * the existing rows. Body keys: `url` (required), `alt`,
     * `is_thumbnail`, `sort_order`, `skroutz_disabled`. Promoting to
     * thumbnail demotes any prior thumbnail in the same transaction.
     */
    public function addImage(int $productId, array $data): Response
    {
        return $this->http->post('/products/product/' . $productId . '/images', $data);
    }

    /**
     * Patch a single image row. Accepts: `is_thumbnail`, `sort_order`,
     * `alt`, `skroutz_disabled`.
     */
    public function updateImage(int $productId, int $imageId, array $data): Response
    {
        return $this->http->put('/products/product/' . $productId . '/images/' . $imageId, $data);
    }

    /**
     * Delete one image row. If the deleted row was the thumbnail and the
     * product still has other images, the next image in sort order is
     * auto-promoted (response carries `thumbnail_promoted: true`).
     */
    public function deleteImage(int $productId, int $imageId): Response
    {
        return $this->http->delete('/products/product/' . $productId . '/images/' . $imageId);
    }

    /**
     * Documented payload shape for any endpoint accepting `custom_fields`.
     * Kept as a constant so IDEs can surface it, even though PHP arrays are
     * structurally typed.
     */
    public const CUSTOM_FIELDS_SHAPE = <<<DOC
[
  { "label": "Color", "values": ["Red", "Blue"] },          // by name; finds-or-creates
  { "label_id": 7, "value_ids": [12, 14] },                  // by id; strict
  { "label": "Size", "values": [{"value_id": 9}, {"value": "XXL"}] }  // mixed
]
DOC;

    /**
     * Documented payload shape for the `images` key on create / update /
     * batchCreate / batchUpdate.
     *
     * Server downloads each URL via MEDIAMANAGER::importImagesFromUrls,
     * dedupes against existing media by remote URL and content hash, and
     * attaches the resulting media rows to the product. Sending `images`
     * on an update REPLACES the product's image set (matches admin
     * behavior); omit the key to leave existing images alone.
     *
     *   url               required — the source URL (http/https only)
     *   alt               optional — per-product alt text, stored in
     *                                product_images.imagedesc, surfaced as
     *                                <img alt="..."> on the storefront
     *   description       optional — alias for alt; if both are set, alt wins
     *   is_thumbnail      optional — true marks this as the product's
     *                                primary image. Only ONE image can be
     *                                the thumbnail; if multiple are set,
     *                                the first wins. If none are set, the
     *                                first image is auto-promoted.
     *   sort_order        optional — int; defaults to payload order
     *   skroutz_disabled  optional — bool; excludes this image from Skroutz
     *                                marketplace feeds
     *
     * The response includes `images_result` with per-URL outcomes so a
     * single broken URL doesn't fail the whole product write. The DB-side
     * DELETE + INSERTs are wrapped in a transaction — if the write fails
     * mid-batch the product keeps its previous images and `images_result`
     * also carries a top-level `error` field with the underlying message.
     */
    public const IMAGES_SHAPE = <<<DOC
[
  {
    "url": "https://cdn.example.com/bag-front.jpg",
    "alt": "Brown leather messenger bag, front view",
    "is_thumbnail": true,
    "sort_order": 0
  },
  {
    "url": "https://cdn.example.com/bag-side.jpg",
    "alt": "Side view"
  },
  "https://cdn.example.com/bag-back.jpg"  // bare URL string also accepted
]
DOC;
}
