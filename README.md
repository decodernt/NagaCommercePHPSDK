# NagaCommerce PHP SDK

A lightweight PHP SDK for the NagaCommerce REST API. No external dependencies — only cURL.

## Requirements

- PHP 7.4 or later
- cURL extension
- JSON extension

## Installation

### With Composer

```json
{
    "repositories": [
        { "type": "path", "url": "./NagaCommercePHPSDK" }
    ],
    "require": {
        "nagacommerce/sdk": "*"
    }
}
```

Then run `composer update`.

### Manual Autoloading

```php
spl_autoload_register(function ($class) {
    $prefix = 'NagaCommerce\\SDK\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = __DIR__ . '/NagaCommercePHPSDK/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
```

## Quick Start

```php
use NagaCommerce\SDK\Client;

$client = new Client('https://your-store.com/api', 'your-api-key');

// Verify connection + list scopes
$scopes = $client->sync()->verify()->getData()['scopes'];

// Get a product (now includes custom_fields)
$product = $client->products()->get(123)->getData();

// Order
$order = $client->orders()->get('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4')->getData();
```

## Authentication

The SDK sends the API key as a Bearer token in the `Authorization` header. Obtain it from the NagaCommerce admin panel under **Settings > API Keys**.

Each key has scopes that gate what it can do:

| Scope | Allows |
|-------|--------|
| `products.read` | List, get, search, custom-field definitions, per-product CF reads |
| `products.write` | Create / update, inventory writes, custom field writes |
| `products.delete` | Cascade-delete a product |
| `products.export` | Use the bulk `/export/products` endpoint |
| `brands.read` / `brands.write` | Brand reads / writes |
| `categories.read` / `categories.write` | Category reads / writes |
| `customers.view` / `customers.create` / `customers.update` / `customers.delete` | Customer CRUD |
| `pricelists.read` | Pricelists + their items |
| `news.read` | Articles + news categories |
| `news.write` | Create / update articles (with images) |
| `news.delete` | Delete articles (cascades to gallery + search + comments) |
| `media.write` | Upload media to the library (no entity attachment required) |
| `system.settings` | Required by `modules()` discovery endpoints (list payment / shipping / analytics modules + addons) |
| `reference.read` | Required by `reference()` lookup endpoints (currencies, customer groups, tax classes, product availabilities) |
| `orders.read` | List, get, count, updated-since |
| `orders.write` | Create, cancel, update status |
| `orders.custom_pricing` | Submit orders with explicit unit prices (otherwise catalog pricing wins) |
| `sync.read` | `/sync/verify` and `/sync/status` |

---

## Resources

The client exposes one accessor per resource, lazily instantiated:

```php
$client->products();    // /api/products
$client->orders();      // /api/orders
$client->brands();      // /api/brands
$client->categories();  // /api/categories
$client->customers();   // /api/customers
$client->pricelists();  // /api/pricelists
$client->news();        // /api/articles + /api/news-categories + /api/news-comments
$client->export();      // /api/export/products
$client->sync();        // /api/sync
$client->media();       // /api/media — standalone media library
$client->modules();     // /api/system/modules + /api/system/addons — discovery
$client->reference();   // /api/reference — lookup tables (currencies, customer groups, tax classes, availabilities)
```

Need a route the SDK doesn't expose yet? Use the underlying HTTP client:

```php
$client->getHttpClient()->get('/some/new/endpoint');
$client->getHttpClient()->post('/some/new/endpoint', ['foo' => 'bar']);
```

---

## Products

```php
$products = $client->products();

$products->count();                                        // { count: N }
$products->updatedSince(1717200000, ['limit' => 100]);     // since UNIX ts
$products->get(123);                                       // single product + custom_fields
$products->search([ 'search_query' => 'bag', 'limit' => 20 ]);
$products->create([ 'name' => 'X', 'sku' => 'X-1', 'price' => 9.9 ]);
$products->update(123, [ 'price' => 7.9 ]);
$products->delete(123);                                    // refuses if order line items exist (409)
```

### Search parameters

| Key | Type | Notes |
|-----|------|-------|
| `search_query` | string | Free text |
| `category_ids` | int[] | CSV also accepted |
| `brand_ids` | int[] | CSV also accepted |
| `sort` | string | `name`, `name_desc`, `id`, `id_desc`, `date`, `date_desc`, `priceasc`, `pricedesc`, `newest`, `featured`, `bestselling`, `relevance`, `avgcustomerreview` |
| `start` | int | offset |
| `limit` | int | page size |

### Product fields (create / update)

```php
$client->products()->create([
    'name'              => 'Leather Messenger Bag',
    'sku'               => 'BAG-LM-001',
    'price'             => 89.90,
    'sale_price'        => 79.90,
    'cost_price'        => 45.00,
    'description'       => '<p>Handcrafted leather messenger bag.</p>',
    'short_description' => 'Hand-stitched bag',
    'weight'            => 1.2,
    'inventory'         => 50,
    'low_inventory'     => 5,
    'inventory_track'   => 1,                 // 0 none, 1 product, 2 variation
    'allow_backorder'   => 1,                 // honored when inventory_track > 0
    'max_backorder_quantity' => 10,
    'visible'           => 1,
    'featured'          => 0,
    'allow_purchases'   => 1,
    'free_shipping'     => 0,
    'fixed_shipping'    => 0.00,
    'min_qty'           => 1,
    'max_qty'           => 99,
    'brand_id'          => 3,
    'availability_id'   => 'in_stock',
    'condition'         => 'New',
    'ean'               => '5715216667019',
    'mpn'               => 'LM-001',
    'gtin'              => 'gtin-1',
    'upc'               => 'upc-1',
    'isbn'              => '978-3-16-148410-0',
    'product_curl'      => 'leather-messenger-bag',
    'page_title'        => 'Leather Messenger Bag | YourStore',
    'meta_description'  => '...',
    'search_keywords'   => 'messenger, leather, bag',
    'type'              => 0,                 // product type (0 = standard)
    'layout_file'       => 'product-special.html',
    'categories'        => [5, 12],
    'custom_fields'     => [
        ['label' => 'Color', 'values' => ['Brown', 'Black']],
    ],
]);
```

Anything starting with `prod` (e.g. `prodvendorid`, `prodhideprice`, `prodpreorder`) also passes through; the friendly names above are the documented surface.

### Images (by URL)

Send `images` on create / update / batch and the server downloads each URL via the same MEDIAMANAGER importer the XML feed importer uses. Media is deduped by remote URL + content hash, so reposting the same URLs is cheap.

```php
$client->products()->create([
    'name'   => 'Leather Messenger Bag',
    'sku'    => 'BAG-LM-001',
    'price'  => 89.90,
    'images' => [
        [
            'url'          => 'https://cdn.example.com/bag-front.jpg',
            'alt'          => 'Brown leather messenger bag, front view',
            'is_thumbnail' => true,
            'sort_order'   => 0,
        ],
        [
            'url'         => 'https://cdn.example.com/bag-side.jpg',
            'alt'         => 'Side view',
            'description' => 'Hand-stitched seam detail visible on the gusset',
        ],
        'https://cdn.example.com/bag-back.jpg', // bare URL also accepted
    ],
]);
```

| Key | Type | Notes |
|-----|------|-------|
| `url` | string | Required. `http://` or `https://` only. |
| `alt` | string | Stored in `product_images.imagedesc`. Surfaced as `<img alt="...">`. |
| `description` | string | Alias for `alt`. If both are set, `alt` wins. |
| `is_thumbnail` | bool | Marks the primary image. Only one can be the thumbnail per product — if multiple are set, the first wins; if none are set, the first image is auto-promoted. |
| `sort_order` | int | Defaults to payload order. |
| `skroutz_disabled` | bool | Excludes from Skroutz marketplace feeds. |

The response carries `data.images_result` with per-URL outcomes:

```php
[
    'attached' => 2,
    'failed'   => 1,
    'results'  => [
        ['url' => '.../bag-front.jpg', 'success' => true,  'media_id' => 4521],
        ['url' => '.../bag-side.jpg',  'success' => true,  'media_id' => 4522],
        ['url' => '.../bag-bad.jpg',   'success' => false, 'error' => 'HTTP 404'],
    ],
]
```

A single broken URL **does not** fail the whole product write — the product is still created/updated, and the broken URL surfaces in `images_result` so the caller can re-queue just that one.

Sending `images` on **update** replaces the entire image set (matches admin behavior). Omit `images` from an update to leave existing images alone. Sending an empty `images: []` clears all images.

**Per-request cap:** 100 images max per create/update call. Oversize image payloads are rejected wholesale with `images_result.error` — the rest of the product write still succeeds, so partners can re-send the image batch in smaller pages without losing the field updates. Re-uploading the same URL is cheap (server dedupes by URL + content hash), so pagination is the right pattern when you legitimately have > 100 images on one product.

**Atomicity:** the DELETE of existing `product_images` rows and the INSERT of the new ones happen inside a single DB transaction. If a row fails to insert mid-batch (e.g. a DB constraint trip), the entire image change is rolled back — the product keeps its previous images, and `images_result` carries an additional `error` field with the underlying message. URL-download failures stay non-fatal as before; the transaction wrap only protects against DB-side write failures, not CDN failures.

```php
// Catastrophic DB failure during write:
[
    'attached' => 0,
    'failed'   => 2,
    'results'  => [...],
    'error'    => 'DB error during product_images write: ...',
]
```

### Inventory

```php
// Apply deltas (additive)
$client->products()->adjustStock(
    [
        ['product_id' => 100, 'delta' => -2],
        ['variation_id' => 55, 'delta' => +5],
    ],
    ['source' => 'partner-wms', 'reason' => 'cycle_count']
);

// Set absolute quantities
$client->products()->setStock(
    [
        ['product_id' => 100, 'quantity' => 48],
        ['variation_id' => 55, 'quantity' => 12],
    ],
    ['source' => 'partner-wms']
);
```

Both flow through the server-side `Store_Inventory` service, so the audit ledger and event listeners (low-stock, back-to-stock, out-of-stock) fire normally.

### Custom fields

**Per-product assignments:**

```php
// Read
$client->products()->getCustomFields(123);

// Replace whole set (clear then add) — matches admin behavior
$client->products()->replaceCustomFields(123, [
    ['label'    => 'Color', 'values'    => ['Red', 'Blue']],
    ['label_id' => 7,       'value_ids' => [12, 14]],
]);

// Append without clearing
$client->products()->appendCustomFields(123, [
    ['label' => 'Material', 'values' => ['Leather']],
]);

// Remove one
$client->products()->removeCustomField(123, $labelId = 7, $valueId = 12);

// Clear all
$client->products()->clearCustomFields(123);
```

Payload shapes accepted everywhere `custom_fields` is taken:

```jsonc
[
  { "label": "Color", "values": ["Red", "Blue"] },           // by name; finds-or-creates
  { "label_id": 7, "value_ids": [12, 14] },                  // by id; strict
  { "label": "Size", "values": [{ "value_id": 9 }, { "value": "XXL" }] }
]
```

**Store-wide definitions** (labels + values):

```php
$client->products()->listCustomFieldDefinitions();

$labelId = $client->products()
    ->createCustomFieldLabel('Color', ['visible' => 1, 'sort_order' => 0])
    ->getData()['label_id'];

$client->products()->updateCustomFieldLabel($labelId, ['label_name' => 'Colour']);
$client->products()->deleteCustomFieldLabel($labelId);   // cascades to values + assignments

$valueId = $client->products()
    ->createCustomFieldValue($labelId, 'Forest Green')
    ->getData()['value_id'];

$client->products()->updateCustomFieldValue($valueId, ['value' => 'Forest']);
$client->products()->deleteCustomFieldValue($valueId);
```

---

## Orders

```php
$orders = $client->orders();

// status 11 = ORDER_STATUS_AWAITING_FULFILLMENT. See the full status
// table under "Order statuses" below.
$orders->list([ 'limit' => 50, 'status' => 11 ]);
$orders->count();
$orders->updatedSince(1717200000, ['limit' => 100]);
$orders->get('a1b2c3d4...');                  // 32-char hex token, not numeric id
$orders->create($orderData);                  // see below
$orders->updateStatus(1025, 11, 'Ready');
$orders->cancel(1025, 'a1b2c3d4...');         // needs the order_token from create()
```

### Pricing modes

Pricing modes are decided server-side from the key's scopes:

- **Catalog pricing** — send `customer.id` + `items[{ product_id, quantity, variation_id? }]`. Prices come from the catalog + customer group.
- **Custom pricing** (requires `orders.custom_pricing`) — each item carries `unit_price`, `price_includes_vat`, `vat_ratio`.

```php
$client->orders()->create([
    'customer' => ['id' => 42],
    'shipping_address' => [
        'firstname' => 'John', 'lastname' => 'Doe',
        'address1' => '123 Main St', 'city' => 'Athens',
        'country' => 'GR', 'zip' => '10431',
        'phone' => '+30 210 1234567',
    ],
    'items' => [
        ['product_id' => 101, 'quantity' => 2],
        ['product_id' => 205, 'quantity' => 1, 'variation_id' => 34],
    ],
]);
```

### Module assignment (payment + shipping)

`payment_method` and `shipping.module` accept the **module id** of an installed-and-configured module on the target store. **Never hard-code these strings** — what's installed varies per store. Always discover them at runtime via `$client->modules()` (see [Modules](#modules) below).

```php
$paymentId  = $client->modules()->payment()->getData()[0]['id'];
$shippingId = $client->modules()->shipping()->getData()[0]['id'];

$client->orders()->create([
    'customer'         => ['id' => 42],
    'shipping_address' => [/* ... */],
    'shipping'         => ['module' => $shippingId, 'cost' => 5.00, 'description' => 'Standard'],
    'items'            => [['product_id' => 101, 'quantity' => 1]],
    'payment_method'   => $paymentId,
]);
```

The server **validates** both ids before constructing the order. An unknown / disabled / unconfigured module returns HTTP 400 with an explicit error message naming the offending id and pointing to the discovery endpoint. The order is NOT created on validation failure — no silent fallback to a default. Empty `shipping.module` is allowed (used by flat-fee / custom-shipping flows that set `is_custom: true`).

### `channel_id`

Identifies the sales channel the order originated from. Constants from `library/NagaCommerce/Channels.php`:

| Constant | Value |
|---|---|
| `SIMPLE` | 0 |
| `PHONE_ORDER` | 50 |
| `IN_STORE` | 80 |
| `EBAY` | 100 |
| `SKROUTZ` | 200 |
| `BESTPRICE` | 300 |
| `SHOPFLIX` | 400 |
| `PUBLIC_MARKETPLACE` | 500 |
| `EMAG` | 600 |
| `AMAZON` | 700 |
| `ETSY` | 800 |
| `IMPORT` | 900 |
| `BASELINKER` | 1000 |
| `TRENDYOL` | 1100 |

Default is `SIMPLE` (0) when omitted. Use the channel that matches your integration's actual origin so reporting + analytics group correctly.

### Order statuses

The numeric `status` field is the value of an `ORDER_STATUS_*` constant from `library/init.php`:

| Code | Constant |
|---|---|
| 0 | `ORDER_STATUS_INCOMPLETE` |
| 1 | `ORDER_STATUS_PENDING` |
| 2 | `ORDER_STATUS_SHIPPED` |
| 3 | `ORDER_STATUS_PARTIALLY_SHIPPED` |
| 4 | `ORDER_STATUS_REFUNDED` |
| 5 | `ORDER_STATUS_CANCELLED` |
| 6 | `ORDER_STATUS_DECLINED` |
| 7 | `ORDER_STATUS_AWAITING_PAYMENT` |
| 8 | `ORDER_STATUS_AWAITING_PICKUP` |
| 9 | `ORDER_STATUS_AWAITING_SHIPMENT` |
| 10 | `ORDER_STATUS_COMPLETED` |
| 11 | `ORDER_STATUS_AWAITING_FULFILLMENT` |
| 12 | `ORDER_STATUS_ONRETURN` |
| 13 | `ORDER_STATUS_PARTIALLY_RETURNED` |
| 14 | `ORDER_STATUS_RETURNED` |
| 15 | `ORDER_STATUS_PARTIALLY_REFUNDED` |

`cancel()` rejects orders currently in `SHIPPED` (2), `PARTIALLY_SHIPPED` (3), `REFUNDED` (4), `CANCELLED` (5), or `RETURNED` (14) with HTTP 409.

---

## Brands

```php
$client->brands()->list();
$client->brands()->search(['search' => 'levi', 'limit' => 10]);
$client->brands()->create([
    'brandname' => 'Levi\'s',
    'brandslug' => 'levis',
    'image_url' => 'https://cdn.example.com/levis.png',  // downloaded server-side
]);  // idempotent on brandname — returns existing with meta.existing = true
$client->brands()->update($brandId, [
    'brandname' => 'Levi Strauss',
    'image_url' => 'https://cdn.example.com/levis-v2.png',
]);
```

**Image handling:** the canonical key is `image_url`. The legacy `brandimagefile` key is still accepted — if the value starts with `http://` or `https://` it's treated as a URL to download, otherwise as a stored media path. URL imports go through MEDIAMANAGER (same path as products/news), dedupe by remote URL + content hash, and surface a per-URL outcome under `data.image_result`:

```php
$response = $client->brands()->update($brandId, [
    'image_url' => 'https://cdn.example.com/levis.png',
]);
$result = $response->getData()['image_result'] ?? null;
// $result = ['success' => true, 'media_id' => 4521, 'url' => 'https://...']
//        OR ['success' => false, 'error' => '...', 'url' => '...']
```

A failed image import does NOT block the brand write — other fields still land, and you can re-queue just the URL.

---

## Categories

```php
$client->categories()->list();             // nested-set ordered, with `url` and `image`
$client->categories()->search(['search' => 'bag', 'limit' => 10]);
$client->categories()->get(5);
$client->categories()->create([
    'catname'     => 'Bags',
    'catparentid' => 0,
    'catvisible'  => 1,
    'image_url'   => 'https://cdn.example.com/bags-category.jpg',  // optional, downloaded server-side
]);  // triggers RebuildCategoryTreeByParent server-side
$client->categories()->update(5, [
    'catname'   => 'Handbags',
    'image_url' => 'https://cdn.example.com/handbags-v2.jpg',
]);

// Batch create — up to 500 rows including sub-categories. Children reference
// their parent in this batch via `parent_ref`, or an existing category by
// `catparentid` (catparentid wins when both are set).
$client->categories()->batchCreate([
    ['ref' => 'bags',    'catname' => 'Bags'],
    ['ref' => 'wallets', 'catname' => 'Wallets', 'parent_ref' => 'bags'],
    ['catname' => 'Coin Purses', 'parent_ref' => 'wallets'],
    ['catname' => 'Belts',       'catparentid' => 42],  // existing parent
]);
// Response data:
//   { results: [{ index, ref?, success, category_id|error }, ...],
//     created, failed, total }
```

Allowed fields: `catname`, `catcurl`, `catdesc`, `catparentid`, `catvisible`, `catsort`, `catpagetitle`, `catmetadesc`, `catmetakeywords`, `catimageid`, `catfeatured`. Plus `ref` and `parent_ref` on the batch endpoint.

---

## Customers

```php
$client->customers()->docs();              // see the live payload schema
$client->customers()->docs('create');

$client->customers()->get(42);             // by id
$client->customers()->get('jane@example.com');  // also by email

$client->customers()->search(['email' => 'jane@example.com']);

$client->customers()->create([             // ALWAYS bulk — max 500 per call
    ['email' => 'a@x.com', 'firstname' => 'Alice'],
    ['email' => 'b@x.com', 'firstname' => 'Bob'],
]);

$client->customers()->update(['id' => 42, 'firstname' => 'Janet']);
$client->customers()->updateByEmail('jane@example.com', ['firstname' => 'Janet']);

$client->customers()->delete(42);          // POST under the hood (server route)
```

The customer payload uses the data-mapper's friendly keys (`email`, `firstname`, `lastname`, `phone`, ...). Run `docs()` against your store to see exactly which keys are accepted.

---

## Pricelists

```php
$client->pricelists()->list();                      // only lists ones assigned to this API key
$client->pricelists()->items($priceListId, [
    'start' => 0,
    'limit' => 100,                                 // server caps at 500
]);
```

---

## News

```php
$client->news()->listArticles();                    // all categories
$client->news()->listArticles('press');             // by category slug
$client->news()->getArticle('our-new-product');     // by slug or id
$client->news()->searchArticles('summer sale');

$client->news()->listCategories();
$client->news()->getCategory('press');
$client->news()->searchCategories(['search' => 'event']);
```

### Create / update / delete articles

```php
// Create — `newstitle` is the only required field. Sending `images`
// downloads each URL server-side via MEDIAMANAGER (same path the product
// image importer uses) and attaches them to the article's gallery.
$client->news()->createArticle([
    'newstitle'   => 'Spring Collection Launch',
    'newscontent' => '<p>Body of the article.</p>',
    'newsvisible' => 1,                              // default 0 — admin must publish
    'categories'  => [3, 7],                         // CSV-encoded server-side
    'tags'        => ['featured', 'launch'],
    'images'      => [
        ['url' => 'https://cdn.example.com/spring-hero.jpg', 'alt' => 'Spring hero', 'is_thumbnail' => true],
        ['url' => 'https://cdn.example.com/spring-detail.jpg', 'alt' => 'Stitching detail'],
    ],
]);

// Update — partial; only sent fields are written. Sending `images`
// REPLACES the gallery (matches product/article admin behavior). Omit to
// leave existing images alone.
$client->news()->updateArticle($articleId, [
    'newsvisible' => 0,
]);

// Delete — also removes news_gallery, news_search, news_words,
// news_comments in one transaction.
$client->news()->deleteArticle($articleId);
```

Required / optional fields and image-key shape match the corresponding sections under [Products](#products) — same image-import behavior, same `images_result` envelope, same atomicity guarantee.

Scopes: `news.write` for create/update, `news.delete` for delete.

### News comments

```php
$client->news()->listComments(['news_id' => $articleId]);   // filter by article
$client->news()->listComments(['status' => 0]);             // moderation queue (pending)
$client->news()->getComment($commentId);

// Create — `news_id` + `text` required. Defaults to pending (status 0).
$client->news()->createComment([
    'news_id'   => $articleId,
    'text'      => 'Great article!',
    'from_name' => 'Jane Doe',
    // 'user_id' => 42, 'parent_id' => 0, 'status' => 1, 'date' => '2026-01-15T12:00:00Z'
]);

// Update — partial; can't move a comment to another article. Changing
// `status` (0 pending / 1 approved / 2 rejected) recomputes the article's
// approved-comment count.
$client->news()->updateComment($commentId, ['status' => 1]);

// Delete — recomputes the article's comment count in the same call.
$client->news()->deleteComment($commentId);
```

Scopes: `news.read` for list/get, `news.write` for create/update, `news.delete` for delete.

---

## Export

Paginated bulk dump of products with rich filter DSL. Each row carries custom_fields, options, prices, tags.

```php
$client->export()->products(
    page: 1,
    perPage: 100,
    filters: [
        ['field' => 'prodvisible', 'type' => 'is',    'value' => 1],
        ['field' => 'prodprice',   'type' => 'is_more', 'value' => 0],
        ['field' => 'categories',  'type' => 'is_in', 'value' => [5, 12]],
    ],
    priceListIds: []
);
```

Supported `field` → operator pairs:

| field | operators |
|-------|-----------|
| `prodname` | `is`, `contains`, `starts_with`, `ends_with` |
| `prodprice`, `prodsaleprice` | `is`, `is_more`, `is_less`, `is_more_or_equal`, `is_less_or_equal`, `is_in`, `is_not_in` |
| `categories`, `brand`, `prodbrandid`, `productid` | `is`, `is_not`, `is_in`, `is_not_in` |
| `exclude_products`, `exclude_brands` | `is_not`, `is_not_in` |
| `prodvisible` | `is`, `is_not` |
| `pricelist_id` | `is_in` |

---

## Sync

```php
$client->sync()->verify();   // { connected, store_name, store_url, scopes, capabilities, ... }
$client->sync()->status();   // { entities: { products, categories, brands, orders, customers }, timestamp }
```

Run `verify()` first in any integration — it fails fast on bad keys and tells you which capabilities your scopes unlock.

---

## Media

Standalone media library — upload images without attaching to any entity. Used by the artisan-block editor (which embeds `media_id` in block JSON) and any flow that wants to pre-upload media before an entity exists.

```php
// Single URL
$client->media()->uploadByUrl('https://cdn.example.com/hero.jpg');

// Many URLs
$client->media()->uploadByUrl([
    'https://cdn.example.com/a.jpg',
    'https://cdn.example.com/b.jpg',
]);

// With per-image metadata (same shape as products/news `images` payload)
$client->media()->uploadByUrl([
    ['url' => 'https://cdn.example.com/a.jpg', 'alt' => 'A description'],
    ['url' => 'https://cdn.example.com/b.jpg', 'alt' => 'B description'],
]);
```

Response carries per-URL outcomes:

```php
[
    'uploaded' => 2,
    'failed'   => 1,
    'results'  => [
        ['url' => '.../a.jpg', 'success' => true,  'media_id' => 4521, 'media' => [...full media row...]],
        ['url' => '.../b.jpg', 'success' => true,  'media_id' => 4522, 'media' => [...]],
        ['url' => '.../bad.jpg', 'success' => false, 'error' => 'HTTP 404'],
    ],
]
```

The `media` field on success is the full row from the `media` table — `mediaid`, `mediafileorig`, `mediafiletiny/thumb/std/zoom`, `mediamimeType`, `mediadatetime`, etc. Use `media_id` when referencing the upload elsewhere (e.g. embedding in an artisan block, or in a future product create payload).

**Re-uploading is cheap:** the server dedupes by remote URL + content hash. Posting the same URL twice returns the same `media_id`.

**Per-request cap:** 100 URLs max per upload call. Oversize requests are rejected with HTTP 400 (unlike the per-entity image caps which return a partial-success envelope — `/media/upload` has no entity to leave intact). Split into smaller pages.

Scope: `media.write`.

---

## Modules

Discovery endpoints for installed payment, shipping, and analytics modules + addons. Required reading for partner integrations: **the set of installed/configured modules varies per store**, so any hard-coded module id will eventually break. Always discover at runtime.

```php
// Payment modules (server-side: /api/system/modules/checkout/list/...)
$payment = $client->modules()->payment()->getData();
// Each entry: { id, name, description, quicksetup_description, enabled, isConfigured, object: <internal> }
// Pick `id` for `payment_method` on orders()->create(), `name` for UI display.

$shipping  = $client->modules()->shipping()->getData();
$analytics = $client->modules()->analytics()->getData();
$addons    = $client->modules()->addons()->getData();

// Filter — default is 'enabled-configured' (the safe set; matches what the
// order-create validator accepts). Other values: 'enabled', 'all'.
$everyShippingModule = $client->modules()->shipping('all')->getData();
```

**`payment()` actually hits `/checkout/`** — the underlying module directory is named `checkout/`, but partners think in terms of "payment". The SDK preserves the user-facing name; the URL preserves the directory name.

**Connection to order creation:** `orders()->create()` validates `payment_method` and `shipping.module` against this same list (filtered to `enabled-configured`). Driving a partner UI's module selector from `modules()->payment()` / `modules()->shipping()` guarantees the chosen ids will pass server-side validation.

Scope: `system.settings`.

---

## Reference

Read-only lookup tables for the FK-shaped fields used by other write endpoints. Same architecture as `modules()` — discovery here, server-side validation on the write side, partners drive selector UIs from these lists.

| Method | Used by | Returns |
|---|---|---|
| `reference()->currencies()` | `orders()->create()` `currency_id` | `[{ id, code, name, symbol, exchange_rate, decimals, is_default, is_active }]` |
| `reference()->customerGroups()` | `customers()->create()` `custgroupid`, `orders()->create()` `customer.group_id` | `[{ id, name, discount, discount_method, is_default }]` |
| `reference()->taxClasses()` | `products()->create()` / `update()` `tax_class_id` | `[{ id, name }]` |
| `reference()->availabilities()` | `products()->create()` / `update()` `availability_id` | `[{ id, title, color, enabled, sort_order }]` |

```php
$currencies     = $client->reference()->currencies()->getData();
$customerGroups = $client->reference()->customerGroups()->getData();
$taxClasses     = $client->reference()->taxClasses()->getData();
$availabilities = $client->reference()->availabilities()->getData();
```

**Schema note for `availabilities()`:** the `products.prodavailability` column is declared `varchar(250)`, but the actual stored values are integer-shaped strings (`'1'`, `'2'`, ...) — i.e. the `availid`. The admin form, the importer, and the storefront all treat it as the integer availid. Pass the **integer `id`** from `availabilities()` as `availability_id` on product writes. The `title` field is the internal availtitle language-key (`AvailAvailable`, `Availin4-10days`, etc.) — useful for admin reference but NOT what partners send back.

```php
$avail = $client->reference()->availabilities()->getData();
// $avail[0] = ['id' => 1, 'title' => 'AvailAvailable', 'color' => 'in-stock', 'enabled' => true, 'sort_order' => 10]

$client->products()->update($productId, [
    'availability_id' => $avail[0]['id'],  // integer
]);
```

**Server-side validation:**

| Endpoint | Field | Validation |
|---|---|---|
| `orders()->create()` | `currency_id` (when > 0) | must be an active row in `currencies` |
| `orders()->create()` | `customer.group_id` (when > 0) | must exist in `customer_groups` |
| `products()->create()` / `update()` | `tax_class_id` (when > 0) | must exist in `tax_classes` |
| `products()->create()` / `update()` | `availability_id` (when > 0) | must match an enabled `availid` |
| `customers()->create()` | `custgroupid` (when > 0) | must exist in `customer_groups` |

All return HTTP 400 with a specific error message + the discovery endpoint to call. `0` and missing values pass through unchecked (= "default" / "no value").

Scope: `reference.read`.

---

## Error Handling

```php
use NagaCommerce\SDK\Exceptions\ApiException;
use NagaCommerce\SDK\Exceptions\AuthenticationException;
use NagaCommerce\SDK\Exceptions\ValidationException;

try {
    $client->orders()->create($orderData);
} catch (AuthenticationException $e) {
    // 401 or 403 — bad key or missing scope
} catch (ValidationException $e) {
    // 422 — invalid payload
} catch (ApiException $e) {
    // Any other API error
    echo "[{$e->getStatusCode()}] {$e->getErrorTitle()}: {$e->getErrorDetail()}";
}
```

Exception methods: `getStatusCode()`, `getErrorTitle()`, `getErrorDetail()`, `getMessage()`.

---

## Response Object

Every SDK method returns `NagaCommerce\SDK\Http\Response`:

```php
$r = $client->products()->list();

$r->isSuccess();      // HTTP 2xx?
$r->getStatusCode();  // 200
$r->getData();        // the JSON:API `data` key
$r->getMeta();        // the JSON:API `meta` key — pagination lives here
$r->getErrors();      // the `errors` key (empty on success)
$r->getRawBody();     // entire decoded JSON
```

---

## Timeout

Defaults to 30 seconds. Constructor takes a third argument:

```php
$client = new Client('https://your-store.com/api', 'your-api-key', 60);
```

---

## Common pitfalls

- **Order tokens, not ids** — `orders()->get()` and `orders()->cancel()` take the 32-char hex token returned at create time, not the numeric `order_id`. The token is what the SDK persists for follow-up calls.
- **Custom-field replace vs. append** — sending `custom_fields` on `products()->update()` REPLACES the whole set (admin behavior). To add without removing, use `appendCustomFields()`.
- **Bulk customer create** — `customers()->create()` always takes an array of customers, even for a single record. The server caps at 500 per call.
- **Pagination keys** — most endpoints use `start` / `limit`; the export endpoint uses `page` / `per_page`. Yes, this is inconsistent.
- **Delete-on-orders** — `products()->delete()` returns HTTP 409 when the product has order line items. Soft-archive on the product (`visible = 0`) instead if you need to retire it.
- **Image-payload cap** — 100 images max per single product / article create/update; 100 URLs max per `/media/upload`. Oversize requests on entity endpoints land in `images_result.error` (the entity field updates still succeed); oversize `/media/upload` requests return HTTP 400. Server dedupes by URL + content hash, so paginating then re-posting the same URLs is cheap.
- **Never hard-code module ids, reference ids, or status codes** — `payment_method`, `shipping.module`, `channel_id`, `status`, `currency_id`, `customer.group_id`, `tax_class_id`, and `availability_id` all reference real rows / enum constants on the target store. Discover modules via `$client->modules()`, lookup tables via `$client->reference()`, and look up status codes / channel ids against the tables in the [Orders](#orders) section. The server validates these ids on every write and returns HTTP 400 for unknown values.
- **`availability_id` is an integer (the `availid`)** — pass `id` from `reference()->availabilities()`, not the `title`. The underlying column is `varchar(250)` for historical reasons but the stored values are integer-shaped strings.

---

## Contributing / Running tests

The SDK ships with a PHPUnit suite covering every resource method, the URL contract, and the response shapes. The test folder is excluded from the Composer-distributed package (`export-ignore`) but lives in the GitHub repo for contributors and auditors.

```sh
git clone https://github.com/.../NagaCommercePHPSDK.git
cd NagaCommercePHPSDK
composer install
composer test         # or: vendor/bin/phpunit
```

You should see ~68 tests pass. A 69th — the cross-repo URL contract test — auto-skips unless you have the server-side `nagaCommerce` repo checked out as a sibling directory (`../nagaCommerce`). When it does run, it parses every controller's route declarations and asserts each SDK method's URL matches a real server route — the regression guard for path drift between this SDK and the API.
