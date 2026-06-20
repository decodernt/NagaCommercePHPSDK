# NagaCommerce SDK — Examples

End-to-end workflows. Each example is self-contained — paste, set `BASE_URL` and `API_KEY`, run. Pair this with [README.md](README.md) for the per-method reference.

```php
use NagaCommerce\SDK\Client;
use NagaCommerce\SDK\Exceptions\ApiException;
use NagaCommerce\SDK\Exceptions\AuthenticationException;
use NagaCommerce\SDK\Exceptions\ValidationException;

const BASE_URL = 'https://your-store.com/api';
const API_KEY  = 'sk_live_...';

$client = new Client(BASE_URL, API_KEY);
```

---

## 1. Verify connection on startup

Always run this first. Fails fast on bad keys and tells you which capabilities your scopes actually unlock — saves debugging `403 Forbidden` later in the integration.

```php
$verify = $client->sync()->verify()->getData();

if (empty($verify['connected'])) {
    throw new RuntimeException('Could not connect to ' . BASE_URL);
}

echo "Connected to: {$verify['store_name']}\n";
echo "Available scopes: " . implode(', ', $verify['scopes']) . "\n";

if (empty($verify['capabilities']['products'])) {
    throw new RuntimeException('This key does not have products.read — re-issue with the right scopes.');
}
```

---

## 2. Bulk catalog import

Create brands and a category tree first (so products can reference them), then batch-import the products with their custom fields in one call.

The category tree can be created in a single batch call — children reference their parent in the same batch via `parent_ref`, and you can mix in existing parents via `catparentid`. The server topologically sorts so parents land first, and rebuilds the nested-set tree once at the end.

```php
// 1. Brands — create() is idempotent on brandname; existing brands come back with meta.existing = true.
$brand = $client->brands()->create([
    'brandname' => 'Acme Leather Co.',
])->getData();
$brandId = (int)$brand['brandid'];

// 2. Category tree in one call — `parent_ref` resolves to another row in
// this batch; `catparentid` references an already-existing category. The
// server creates parents before children, rebuilds the nested-set tree
// once at the end, and reports per-row results.
$tree = $client->categories()->batchCreate([
    ['ref' => 'bags',         'catname' => 'Bags',         'catvisible' => 1],
    ['ref' => 'wallets',      'catname' => 'Wallets',      'parent_ref' => 'bags'],
    ['ref' => 'coin_purses',  'catname' => 'Coin Purses',  'parent_ref' => 'wallets'],
    ['ref' => 'belts',        'catname' => 'Belts'],  // root
])->getData();

// Resolve refs to the assigned category ids.
$catIds = [];
foreach ($tree['results'] as $row) {
    if ($row['success'] && isset($row['ref'])) {
        $catIds[$row['ref']] = $row['category_id'];
    }
}
$catBags    = $catIds['bags'];
$catWallets = $catIds['wallets'];

// 3. Products — batch of up to 500 per call. Each row uses the same friendly
// field map as create(). custom_fields are find-or-created on the fly.
$response = $client->products()->batchCreate([
    [
        'name'             => 'Leather Messenger Bag',
        'sku'              => 'BAG-LM-001',
        'price'            => 89.90,
        'sale_price'       => 79.90,
        'description'      => '<p>Handcrafted leather messenger bag.</p>',
        'weight'           => 1.2,
        'inventory'        => 50,
        'inventory_track'  => 1,
        'visible'          => 1,
        'ean'              => '5715216667019',
        'brand_id'         => $brandId,
        'categories'       => [$catBags],
        'custom_fields'    => [
            ['label' => 'Color',    'values' => ['Brown', 'Black']],
            ['label' => 'Material', 'values' => ['Full-grain leather']],
        ],
        // URLs are downloaded server-side and deduped against existing media
        // by remote URL + content hash. Reposting the same URLs is cheap.
        'images'           => [
            [
                'url'          => 'https://cdn.example.com/bag-front.jpg',
                'alt'          => 'Brown leather messenger bag, front view',
                'is_thumbnail' => true,
            ],
            [
                'url' => 'https://cdn.example.com/bag-side.jpg',
                'alt' => 'Side view',
            ],
        ],
    ],
    [
        'name'           => 'Bifold Wallet',
        'sku'            => 'WAL-BF-001',
        'price'          => 39.90,
        'inventory'      => 100,
        'inventory_track'=> 1,
        'visible'        => 1,
        'brand_id'       => $brandId,
        'categories'     => [$catWallets],
    ],
]);

$result = $response->getData();
echo "Created: {$result['created']}, failed: {$result['failed']}\n";

foreach ($result['results'] as $row) {
    if (!$row['success']) {
        echo "  Row {$row['index']} failed: {$row['error']}\n";
    }
}
```

---

## 3. Category tree from a CSV / external source

Import a multi-level category hierarchy from a partner feed. The flat list with `parent_ref` matches how most ERPs/PIMs dump trees (CSV with parent name/code per row).

```php
$rows = []; // CSV → rows
$csv = fopen('/tmp/categories.csv', 'r');
$header = fgetcsv($csv); // expects: ref, name, parent_ref
while (($line = fgetcsv($csv)) !== false) {
    $rows[] = [
        'ref'        => $line[0] ?: null,
        'catname'    => $line[1],
        'parent_ref' => $line[2] ?: null,
    ];
}
fclose($csv);

// The server sorts topologically — order in your CSV doesn't matter.
$response = $client->categories()->batchCreate($rows)->getData();

echo "Created: {$response['created']} · Failed: {$response['failed']}\n";

foreach ($response['results'] as $r) {
    if (!$r['success']) {
        // Two common failure modes here:
        //   - catname missing → "catname is required"
        //   - parent failed earlier → "Parent ref `X` was not created"
        echo "  Row {$r['index']}: {$r['error']}\n";
    }
}
```

**Failure model worth knowing:**

- Validation errors (missing `categories` key, > 500 rows, duplicate `ref`, dangling `parent_ref`, cycle) → the **whole batch** is rejected with a `400` (`ApiException`). Catch it at the SDK level.
- Per-row failures (missing `catname`, INSERT error) → reported in `results[].success=false`. Children of a failed parent are flagged with `"Parent ref \`X\` was not created"` so you can re-queue just that subtree.

`catparentid` (existing category id) takes precedence over `parent_ref` when both are set — useful for inserting new sub-trees under an existing root.

---

## 4. Incremental product sync (only what changed)

For nightly mirrors into an ERP/data warehouse. Cheaper than re-pulling the catalog.

```php
$lastSyncedAt = (int)file_get_contents('/tmp/last-sync.txt') ?: 0;

$start = 0;
$limit = 200;
$now   = time();

do {
    $resp = $client->products()->updatedSince($lastSyncedAt, [
        'start' => $start,
        'limit' => $limit,
    ]);

    foreach ($resp->getData() as $product) {
        // Write to your warehouse / ERP / cache. Each row is the full
        // product record from the database.
        upsertIntoWarehouse($product);
    }

    $meta = $resp->getMeta();
    $start += $limit;
} while ($start < (int)($meta['total'] ?? 0));

file_put_contents('/tmp/last-sync.txt', (string)$now);
```

---

## 5. Update a product's images (full replace from a CDN)

`images` on `update()` replaces the entire image set. Useful when your PIM is the source of truth and you re-push the current image list each time it changes.

```php
$response = $client->products()->update($productId, [
    'images' => [
        [
            'url'          => 'https://cdn.example.com/bag-front-v2.jpg',
            'alt'          => 'Brown leather messenger bag, front view',
            'is_thumbnail' => true,
        ],
        [
            'url' => 'https://cdn.example.com/bag-side-v2.jpg',
            'alt' => 'Side view',
        ],
        [
            'url' => 'https://cdn.example.com/bag-detail.jpg',
            'alt' => 'Stitching detail',
            'sort_order' => 99,
        ],
    ],
]);

$imagesResult = $response->getData()['images_result'] ?? null;
if ($imagesResult) {
    // Catastrophic DB-side failure — the whole image change was rolled back,
    // product keeps its previous images. Separate from per-URL failures.
    if (!empty($imagesResult['error'])) {
        error_log("Image write rolled back: {$imagesResult['error']}");
    }

    echo "Attached: {$imagesResult['attached']}, failed: {$imagesResult['failed']}\n";
    foreach ($imagesResult['results'] as $r) {
        if (!$r['success']) {
            error_log("Image failed: {$r['url']} → {$r['error']}");
        }
    }
}
```

**Gotchas:**
- A single broken URL doesn't fail the product write — you get back `images_result` with per-URL outcomes.
- The DELETE-then-INSERT writes happen in a transaction. If the DB write fails mid-batch (constraint trip, deadlock, etc.) the product keeps its previous images and `images_result.error` carries the message. URL-download failures stay non-fatal as before — the transaction only protects against DB write failures.
- **Hard cap: 100 images per request.** Oversize image payloads land in `images_result.error` (the product field updates still succeed). The same cap applies to news articles; the standalone `/media/upload` endpoint returns 400 instead. Paginate at the call-site — reposting the same URLs is cheap.
- Omit the `images` key entirely to leave existing images alone (price-only update etc.).
- Send `images: []` to clear all images.
- Only ONE image per product can be the thumbnail. If multiple set `is_thumbnail: true`, the first one wins.
- `alt` text is per-product (stored in `product_images.imagedesc`). The same media reused on another product can have a different alt there.

---

## 6. Publish a news article with images

Same image-import pattern as products — URLs are downloaded server-side via MEDIAMANAGER, deduped against the existing media library, attached to the article's gallery in a single transaction.

```php
$response = $client->news()->createArticle([
    'newstitle'    => 'Spring Collection Launch',
    'newscontent'  => '<p>The new spring line drops Monday.</p>',
    'newsshortdesc'=> '32 new pieces across three categories.',
    'newsvisible'  => 1,                       // 0 (draft) by default — set 1 to publish
    'newsdate'     => '2026-03-15T09:00:00+00:00',  // ISO 8601 or unix int
    'categories'   => [3, 7],                  // existing news_categories ids
    'tags'         => ['spring2026', 'collection-launch'],
    'images'       => [
        [
            'url'          => 'https://cdn.example.com/spring-hero.jpg',
            'alt'          => 'Spring 2026 hero — model in pastel coat',
            'is_thumbnail' => true,
        ],
        [
            'url' => 'https://cdn.example.com/spring-detail.jpg',
            'alt' => 'Hand-stitched lapel detail',
        ],
    ],
]);

$article = $response->getData();
$articleId = $article['newsid'];

// Per-URL image outcomes (broken CDN links surface here without failing the create):
foreach (($article['images_result']['results'] ?? []) as $r) {
    if (!$r['success']) {
        error_log("News image failed: {$r['url']} → {$r['error']}");
    }
}

// Later — publish a draft.
$client->news()->updateArticle($articleId, ['newsvisible' => 1]);

// Replace the gallery (e.g. agency sent new shots).
$client->news()->updateArticle($articleId, [
    'images' => [
        ['url' => 'https://cdn.example.com/spring-hero-v2.jpg', 'alt' => 'Updated hero'],
    ],
]);

// Remove the article entirely.
$client->news()->deleteArticle($articleId);
```

**Behavior notes** (identical to products):
- Articles are created hidden by default (`newsvisible: 0`). Send `1` to publish.
- `newscurl` (the URL slug) defaults to a slugified title — set it explicitly when you need a stable canonical URL.
- `newsdate` accepts both ISO 8601 strings and unix timestamps.
- Sending `images` on update REPLACES the gallery; omit to leave alone; send `[]` to clear.
- `alt` text per image is stored in `news_gallery.newsimagedesc` (the storefront `<img alt="">` attribute).
- Deletion cascades to `news_gallery`, `news_search`, `news_words`, `news_comments` in one transaction.

Create a news category, then file an article under it:

```php
$cat = $client->news()->createCategory([
    'newscattitle'       => 'Press Releases',
    'newscatdescription' => 'Official company announcements',
    'newscatvisible'     => 1,           // 0 (hidden) by default
])->getData();

$client->news()->createArticle([
    'newstitle'  => 'We raised a Series A',
    'categories' => [$cat['newscategoryid']],
    'newsvisible'=> 1,
]);
```

- Categories are created hidden by default (`newscatvisible: 0`).
- `newscatcurl` (slug) defaults to a slugified title — set it explicitly for a stable URL.
- `title` / `description` must be sent as the raw `newscattitle` / `newscatdescription` columns (no friendly aliases, matching the article create surface).

---

## 7. Pre-upload media for an artisan-block editor

The standalone `/media/upload` endpoint takes URLs, downloads them via MEDIAMANAGER, and returns the resulting media rows — **without attaching to any entity**. Used when:
- You're authoring an artisan block (page/popup/email/banner) and need `media_id`s to embed in the block JSON before the document is saved.
- You're batch-pre-uploading a campaign's images before deciding which products / articles will use which.
- You're drafting content and the target entity doesn't exist yet.

```php
$response = $client->media()->uploadByUrl([
    ['url' => 'https://cdn.example.com/hero.jpg',   'alt' => 'Spring hero'],
    ['url' => 'https://cdn.example.com/detail.jpg', 'alt' => 'Fabric detail'],
    ['url' => 'https://cdn.example.com/dead.jpg'],  // simulate failure
]);

$data = $response->getData();
echo "Uploaded: {$data['uploaded']}, failed: {$data['failed']}\n";

$mediaIds = [];
foreach ($data['results'] as $r) {
    if ($r['success']) {
        $mediaIds[$r['url']] = $r['media_id'];
        echo "  {$r['url']} → media #{$r['media_id']} ({$r['media']['mediafileorig']})\n";
    } else {
        error_log("Media upload failed: {$r['url']} → {$r['error']}");
    }
}

// Embed in an artisan block via the Papillon MCP / artisan admin path:
//   $block['settings']['data']['image_id'] = $mediaIds['https://cdn.example.com/hero.jpg'];
// The standalone uploader's only job is putting the media in the library;
// the artisan-block authoring tool is what places the media_id.
```

**Re-uploading is cheap.** The server dedupes by both remote URL and content hash, so posting the same URL twice returns the same `media_id` and doesn't re-download. Scripts can be idempotent.

```php
// Idempotent pre-upload — safe to re-run on partial-failure retries
function ensureMediaInLibrary(array $urls) use ($client): array {
    $r = $client->media()->uploadByUrl(array_values($urls))->getData();
    $byUrl = [];
    foreach ($r['results'] as $row) {
        if ($row['success']) {
            $byUrl[$row['url']] = $row['media_id'];
        }
    }
    return $byUrl;
}
```

---

## 8. Discover payment + shipping modules (required for order creation)

Module ids vary per store — the set of installed/configured modules depends on what each store admin has set up. Hard-coding `'checkout_paypalapi'` or `'shipping_acscourier'` will work on one store and fail on the next. **Always discover at runtime** before submitting orders.

```php
// Fetch enabled + configured modules (the only set the order-create
// validator accepts). 'enabled-configured' is the SDK default.
$paymentModules  = $client->modules()->payment()->getData();
$shippingModules = $client->modules()->shipping()->getData();

// Each row carries: id, name, description, quicksetup_description, enabled, isConfigured.
// The `id` is what you pass on order create. `name` is the storefront-displayed label.
foreach ($paymentModules as $m) {
    echo "  [{$m['id']}] {$m['name']}\n";
}

// Build an indexed lookup so your selector UI can map user picks → ids.
$paymentByName  = array_column($paymentModules,  'id', 'name');
$shippingByName = array_column($shippingModules, 'id', 'name');

// Server-side validation: passing an id not in this list returns HTTP 400.
// The order is NOT created — no silent fallback to a default.
try {
    $client->orders()->create([
        'customer'         => ['id' => 42],
        'shipping_address' => [/* ... */],
        'items'            => [['product_id' => 101, 'quantity' => 1]],
        'payment_method'   => 'checkout_doesnotexist',
        'shipping'         => ['module' => 'shipping_alsofake', 'cost' => 5],
    ]);
} catch (\NagaCommerce\SDK\Exceptions\ApiException $e) {
    // 400 — "Unknown or unusable payment module: checkout_doesnotexist.
    //        Discover valid ids via GET /api/system/modules/checkout/list/enabled-configured."
    echo $e->getErrorDetail();
}
```

**Why `payment()` hits `/checkout/`:** the server's module directory is `modules/checkout/`, but storefront/admin UI calls it "payment". The SDK preserves the user-facing name (`payment()`), the URL preserves the directory name.

**Other discovery methods:**

```php
$client->modules()->analytics();   // Google Analytics, Skroutz Analytics, etc.
$client->modules()->addons();      // Installed addons (Trendyol, BaseLinker, etc.)

// Filter:
$client->modules()->payment('all');                  // everything in the directory
$client->modules()->payment('enabled');              // EnableService set, may be unconfigured
$client->modules()->payment('enabled-configured');   // default — the safe set
```

**Scope required:** `system.settings` (these endpoints live under `/api/system/...`).

---

## 9. Discover reference data (currencies, customer groups, tax classes, availabilities)

Same architecture as the module discovery (`$client->modules()`): the server has small admin-managed lookup tables, the SDK exposes read-only endpoints to discover them, the write endpoints validate against those lists. Drive partner selector UIs from these lists and the chosen values will always pass server-side validation.

```php
$currencies     = $client->reference()->currencies()->getData();
$customerGroups = $client->reference()->customerGroups()->getData();
$taxClasses     = $client->reference()->taxClasses()->getData();
$availabilities = $client->reference()->availabilities()->getData();

// Example: build an order against discovered currency + customer group ids
$eurId = null;
foreach ($currencies as $c) {
    if ($c['code'] === 'EUR') { $eurId = $c['id']; break; }
}
$wholesale = null;
foreach ($customerGroups as $g) {
    if ($g['name'] === 'Wholesale') { $wholesale = $g['id']; break; }
}

$client->orders()->create([
    'customer'         => ['id' => 42, 'group_id' => $wholesale],
    'currency_id'      => $eurId,
    'shipping_address' => [/* ... */],
    'items'            => [['product_id' => 101, 'quantity' => 2]],
    // payment_method + shipping.module from $client->modules()
]);

// Example: assign tax class + availability on a product
$standardRate = null;
foreach ($taxClasses as $t) {
    // Names depend on what the store admin configured — match against
    // your own naming scheme; "Standard rate" is just illustrative.
    if ($t['name'] === 'Standard rate') { $standardRate = $t['id']; break; }
}
$inStock = null;
foreach ($availabilities as $a) {
    // `title` is the internal availtitle language-key — useful as a
    // stable lookup token. The storefront-displayed label comes from
    // the language file. Pick by `title`, then send `id` on the write.
    if ($a['title'] === 'AvailAvailable') { $inStock = $a['id']; break; }
}

$client->products()->update($productId, [
    'tax_class_id'    => $standardRate,
    'availability_id' => $inStock,  // integer (availid)
]);
```

**`availability_id` schema note:** the column `products.prodavailability` is declared `varchar(250)` but the actual stored values are integer-shaped strings (`'1'`, `'2'`, ...) — i.e. the `availid` integer. Pass the integer **`id`** from `availabilities()` on writes. The `title` field on each row (`AvailAvailable`, `Availin4-10days`, etc.) is the internal language-key used for admin reference — match against it to PICK an availability, but send `id` on the write.

**Server-side validation:**
- `currency_id`, `customer.group_id`, `tax_class_id`, `availability_id`: must exist in their respective tables when > 0. `0` means "default" / "no value" and passes through.
- `availability_id` additionally requires the matching row to be **enabled** (`availenabled = 1`).
- All return HTTP 400 with a specific error and a pointer to the discovery endpoint.

Scope: `reference.read`.

---

## 10. Inventory sync from external WMS

Two modes — pick based on what your source system gives you:

**Deltas (most common)** — your WMS knows that 3 items shipped:

```php
$client->products()->adjustStock(
    [
        ['product_id' => 100, 'delta' => -3],
        ['variation_id' => 55, 'delta' => +12], // restock
    ],
    [
        'source' => 'wms-3pl',
        'reason' => 'cycle_count',
    ]
);
```

**Absolute quantities** — nightly truth-up from a stocktake:

```php
$client->products()->setStock(
    [
        ['product_id' => 100, 'quantity' => 47],
        ['product_id' => 101, 'quantity' => 100],
        ['variation_id' => 55, 'quantity' => 12],
    ],
    ['source' => 'wms-3pl', 'reason' => 'nightly_truth_up']
);
```

Both routes write to the audit ledger and fire `inventory_stock_changed`, `product_went_outofstock`, `product_went_lowstock`, `product_went_backtostock` events server-side — listeners (email alerts, reorder flows) keep working.

---

## 11. Process an inbound order (marketplace)

Marketplace integrations need `orders.custom_pricing` to send explicit unit prices. The server uses catalog pricing otherwise.

The `payment_method` and `shipping.module` ids are **never made up** — they refer to actual modules installed on the target store. Discover them at runtime via `$client->modules()`; the server validates these ids on order create and returns HTTP 400 for unknown / unconfigured modules.

```php
// Step 1 — discover the modules installed on THIS store. The lists are
// filtered to `enabled-configured` by default, which is the safe set the
// order-create validator accepts.
$paymentModules  = $client->modules()->payment()->getData();
$shippingModules = $client->modules()->shipping()->getData();

// Pick the id that matches whatever you're integrating against. Example
// module ids look like: 'checkout_alphabankpos', 'checkout_paypalapi',
// 'checkout_bankdeposit', 'checkout_skroutzpayment', 'checkout_shopflixpayment',
// 'shipping_acscourier', 'shipping_speedex', 'shipping_dpd', etc. — the actual
// set depends on what each store has installed.
$paymentId  = $paymentModules[0]['id'];   // your selector logic here
$shippingId = $shippingModules[0]['id'];

// Step 2 — `channel_id` corresponds to the NagaCommerce_Channels constant
// for whichever sales channel this order originated from. Known channels:
//   SIMPLE=0, PHONE_ORDER=50, IN_STORE=80, EBAY=100, SKROUTZ=200,
//   BESTPRICE=300, SHOPFLIX=400, PUBLIC_MARKETPLACE=500, EMAG=600,
//   AMAZON=700, ETSY=800, IMPORT=900, BASELINKER=1000, TRENDYOL=1100.
// Use whichever applies; SIMPLE (0) is the default for direct API orders.

$response = $client->orders()->create([
    'customer' => [
        'firstname' => 'Jane',
        'lastname'  => 'Smith',
        'email'     => 'jane@example.com',
    ],
    'shipping_address' => [
        'firstname' => 'Jane',
        'lastname'  => 'Smith',
        'address1'  => '45 Harbor Rd',
        'city'      => 'Thessaloniki',
        'country'   => 'GR',
        'zip'       => '54621',
        'phone'     => '+30 2310 000000',
    ],
    'shipping' => [
        'cost'        => 3.50,
        'description' => 'Standard Shipping',
        'module'      => $shippingId,           // validated server-side
    ],
    'items' => [
        [
            'product_id'        => 101,
            'quantity'          => 2,
            'unit_price'        => 29.90,
            'price_includes_vat' => true,
            'vat_ratio'         => 24.0,
        ],
    ],
    'payment_method'        => $paymentId,       // validated server-side
    'payment_display_name'  => $paymentModules[0]['name'],
    'channel_id'            => 400,              // SHOPFLIX, for example
    'channel_order_id'      => 'MP-2026-00123',
]);

$data = $response->getData();
$orderId    = $data['order_id'];
$orderToken = $data['order_token'];

// CRITICAL: persist the token. You cannot cancel the order later without it.
file_put_contents("/var/orders/mp-{$orderId}.token", $orderToken);
```

**Server-side validation:** an unknown `payment_method` or `shipping.module` id returns HTTP 400 with a message naming the offending id and pointing back to the discovery endpoint. The order is NOT created in that case — no half-state, no random fallback.

---

## 12. Order status workflow

```php
// List orders awaiting fulfillment. Status 11 = ORDER_STATUS_AWAITING_FULFILLMENT.
$pending = $client->orders()->list(['status' => 11, 'limit' => 50])->getData();

foreach ($pending as $order) {
    // Get full details — token is on the order list payload
    $detail = $client->orders()->get($order['ordertoken'])->getData();

    if (shouldFulfill($detail)) {
        // 9 = ORDER_STATUS_AWAITING_SHIPMENT
        $client->orders()->updateStatus($detail['orderid'], 9, 'Picked, awaiting carrier');
        // ... ship it ...
        // 2 = ORDER_STATUS_SHIPPED
        $client->orders()->updateStatus($detail['orderid'], 2, 'Shipped via DHL #1Z999...');
    }
}
```

**Full order-status table** (sourced from `library/init.php` — `ORDER_STATUS_*` constants):

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

---

## 13. Cancel an order

Requires the order token from `create()`. Server rejects cancel on Shipped / Partially Shipped / Refunded / Cancelled / Returned orders with a 4xx.

```php
try {
    $r = $client->orders()->cancel($orderId, $orderToken);
    $data = $r->getData();
    echo "Cancelled — was {$data['old_status']}, now {$data['new_status']}\n";
} catch (ApiException $e) {
    if ($e->getStatusCode() === 409) {
        echo "Cannot cancel: " . $e->getErrorDetail() . "\n";
    } else {
        throw $e;
    }
}
```

---

## 14. Custom field taxonomy management

You don't have to use the assignment endpoints — `products()->create()` accepts a `custom_fields` key directly. But when you want to manage the taxonomy itself (rename a label, add a value, hide a field from storefront filters) hit the definition endpoints.

```php
// Define a new label
$label = $client->products()
    ->createCustomFieldLabel('Material', ['visible' => 1, 'sort_order' => 10])
    ->getData();
$labelId = $label['label_id'];

// Add allowed values up front so partners reference them by id
foreach (['Cotton', 'Polyester', 'Wool', 'Cashmere'] as $value) {
    $client->products()->createCustomFieldValue($labelId, $value);
}

// Now assign to products by id (strict — fails if id doesn't match the label)
$cottonId = $client->products()->createCustomFieldValue($labelId, 'Cotton')->getData()['value_id'];

$client->products()->replaceCustomFields(123, [
    ['label_id' => $labelId, 'value_ids' => [$cottonId]],
]);

// Read back
$assigned = $client->products()->getCustomFields(123)->getData();
foreach ($assigned as $group) {
    echo "{$group['label_name']}: " . implode(', ', array_column($group['values'], 'value')) . "\n";
}

// Hide a label from storefront filters without deleting the data
$client->products()->updateCustomFieldLabel($labelId, ['visible' => 0]);
```

---

## 15. Search + filter (storefront-style discovery)

The find endpoint runs through the server's FULLTEXT index on `product_search` (kept in sync automatically when you create/update products via this SDK).

```php
$results = $client->products()->search([
    'search_query' => 'leather messenger',
    'category_ids' => [$catBags],
    'brand_ids'    => [$brandId],
    'sort'         => 'priceasc',
    'limit'        => 20,
])->getData();

foreach ($results as $product) {
    echo "[{$product['productid']}] {$product['prodname']} — {$product['prodcalculatedprice']}\n";
}
```

---

## 16. Bulk export with filters

Heavy paginated dump for catalog feeds, BI loads, partner price lists. Pagination uses `page` + `per_page` (not `start`/`limit` like the read endpoints — yes, inconsistent).

```php
$page = 1;
do {
    $r = $client->export()->products(
        page: $page,
        perPage: 100,
        filters: [
            ['field' => 'prodvisible',  'type' => 'is',     'value' => 1],
            ['field' => 'prodprice',    'type' => 'is_more','value' => 0],
            ['field' => 'categories',   'type' => 'is_in',  'value' => [$catBags, $catWallets]],
        ]
    );

    foreach ($r->getData() as $product) {
        // Each row is rich: products + their custom_fields, options, prices, tags
        writeFeedRow($product);
    }

    $meta = $r->getMeta();
    $page++;
} while ($page <= ($meta['total_pages'] ?? 1));
```

---

## 17. Pricelist consumption (partner integration)

Partner stores expose only their assigned pricelists — the server enforces this via the API key. You don't need to know the pricelist ids up front.

```php
foreach ($client->pricelists()->list()->getData() as $list) {
    echo "Pricelist: {$list['name']} ({$list['currency']})\n";

    $start = 0;
    $limit = 100;
    do {
        $r = $client->pricelists()->items((int)$list['pl_id'], [
            'start' => $start,
            'limit' => $limit,
        ]);
        foreach ($r->getData() as $item) {
            applyPartnerPrice(
                (int)$item['productid'],
                (int)$item['combinationid'],
                (float)$item['prodprice'],
                (float)$item['prodsaleprice']
            );
        }
        $start += $limit;
    } while ($start < (int)($r->getMeta()['total'] ?? 0));
}
```

---

## 18. Cleanup: archive stale products

`delete()` returns HTTP 409 when the product still has order line items — those products need archival, not deletion. The batch endpoint handles this for you (skips them automatically) but here's the single-record pattern with explicit fallback.

```php
$staleIds = findProductsNotSoldInPastYear(); // your logic

foreach ($staleIds as $id) {
    try {
        $client->products()->delete($id);
        echo "Deleted: {$id}\n";
    } catch (ApiException $e) {
        if ($e->getStatusCode() === 409) {
            // Has order history — archive instead of delete.
            $client->products()->update($id, ['visible' => 0, 'allow_purchases' => 0]);
            echo "Archived: {$id}\n";
        } else {
            throw $e;
        }
    }
}

// Batch variant — server returns deleted vs skipped_has_orders vs not_found:
$result = $client->products()->batchDelete($staleIds)->getData();
echo count($result['deleted']) . " deleted, "
   . count($result['skipped_has_orders']) . " skipped (have orders), "
   . count($result['not_found']) . " not found\n";

// Archive the ones we couldn't delete
foreach ($result['skipped_has_orders'] as $id) {
    $client->products()->update($id, ['visible' => 0]);
}
```

---

## 19. Bulk customer import

Customer create is **always bulk** (max 500 per call) — even for one customer, wrap it in an array.

```php
$response = $client->customers()->create([
    [
        'email'     => 'jane@example.com',
        'firstname' => 'Jane',
        'lastname'  => 'Smith',
        'phone'     => '+30 210 0000000',
    ],
    [
        'email'     => 'john@example.com',
        'firstname' => 'John',
        'lastname'  => 'Doe',
    ],
]);
// Response includes per-row outcomes — see customers()->docs() for the exact shape on your store.
```

Need to update by email (no NC id on your side)? `updateByEmail`:

```php
$client->customers()->updateByEmail('jane@example.com', [
    'firstname' => 'Janet',
]);
```

---

## 20. Robust error handling

Throw-based exceptions — wrap once at a high level for an integration that talks to the API repeatedly.

```php
function call(callable $fn, int $retries = 3): mixed
{
    $delayMs = 200;
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            return $fn();
        } catch (AuthenticationException $e) {
            // 401/403 — bad key or missing scope. Don't retry.
            error_log("Auth: {$e->getErrorDetail()}");
            throw $e;
        } catch (ValidationException $e) {
            // 422 — payload is wrong. Don't retry, log + skip.
            error_log("Validation: {$e->getErrorDetail()}");
            throw $e;
        } catch (ApiException $e) {
            // 5xx + network errors — backoff + retry.
            if ($e->getStatusCode() >= 500 || $e->getStatusCode() === 0) {
                if ($attempt === $retries) throw $e;
                usleep($delayMs * 1000);
                $delayMs *= 2;
                continue;
            }
            // 4xx other than auth/validation — surface to caller.
            throw $e;
        }
    }
}

// Usage
$products = call(fn() => $client->products()->search(['search_query' => 'bag']))
    ->getData();
```

---

## 21. Use the raw HTTP client for unmapped routes

If a server route ships before the SDK adds a method, the underlying HTTP client is reachable:

```php
$http = $client->getHttpClient();

$http->get('/some/new/endpoint', ['param' => 'value']);
$http->post('/some/new/endpoint', ['foo' => 'bar']);
$http->put('/some/resource/123', ['name' => 'X']);
$http->delete('/some/resource/123');
```

Every primitive returns the same `Response` object as the resource methods.

---

## Common patterns

**Pagination:** read endpoints use `start` / `limit`; export uses `page` / `per_page`. Server caps vary — products updated-since caps `limit` at 500, orders list at 200, pricelist items at 500.

**Idempotency:** brand create, custom-field label create, and custom-field value create all return existing rows on duplicate — safe to retry.

**Order tokens, not ids:** `orders()->get()` and `orders()->cancel()` take the 32-char hex token returned at create time. Persist it.

**Custom-field replace vs append:** sending `custom_fields` via `products()->update()` REPLACES the set (admin behavior). Use `appendCustomFields()` to add without clearing.

**Scopes drive pricing mode:** `orders.custom_pricing` lets you send explicit `unit_price`; without it, the server prices from the catalog. Verify which mode is active via `sync()->verify()`.
