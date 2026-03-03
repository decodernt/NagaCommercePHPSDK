# NagaCommerce PHP SDK

A lightweight PHP SDK for the NagaCommerce REST API. No external dependencies — only cURL.

## Requirements

- PHP 7.4 or later
- cURL extension
- JSON extension

## Installation

### With Composer

Copy the `SDK/` folder into your project, then add to your `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "./SDK" }
    ],
    "require": {
        "nagacommerce/sdk": "*"
    }
}
```

Then run `composer update`.

### Manual Autoloading

If you're not using Composer, include the autoloader:

```php
spl_autoload_register(function ($class) {
    $prefix = 'NagaCommerce\\SDK\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = __DIR__ . '/SDK/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
```

## Quick Start

```php
use NagaCommerce\SDK\Client;

$client = new Client('https://your-store.com/api', 'your-api-key');

// List products
$response = $client->products()->list();
$products = $response->getData();

// Get an order by its token
$response = $client->orders()->get('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
$order = $response->getData();
```

## Authentication

The SDK authenticates using an API key sent as a `Bearer` token in the `Authorization` header. Obtain your API key from the NagaCommerce admin panel under **Settings > API Keys**.

Each API key has scopes that control what operations it can perform:

| Scope | Allows |
|-------|--------|
| `products.read` | List, get, search products |
| `products.write` | Create and update products |
| `orders.read` | List and get orders |
| `orders.write` | Create, cancel, and update order status |
| `orders.custom_pricing` | Submit orders with explicit prices (otherwise catalog pricing is used) |

---

## Products

### List all products

```php
$response = $client->products()->list();
$products = $response->getData();
```

### Get product count

```php
$response = $client->products()->count();
$count = $response->getData()['count'];
```

### Get a single product

```php
$response = $client->products()->get(123);
$product = $response->getData();
```

### Search products

```php
$response = $client->products()->search([
    'search_query' => 'diamond bag',
    'category_ids' => [5, 12],
    'brand_ids'    => [3],
    'sort'         => 'priceasc',
    'start'        => 0,
    'limit'        => 20,
]);

$products = $response->getData();
$total = $response->getMeta()['total'];
```

### Create a product

```php
$response = $client->products()->create([
    'name'        => 'Leather Messenger Bag',
    'sku'         => 'BAG-LM-001',
    'price'       => 89.90,
    'description' => '<p>Handcrafted leather messenger bag.</p>',
    'weight'      => 1.2,
    'inventory'   => 50,
    'visible'     => 1,
    'ean'         => '5715216667019',
    'mpn'         => 'LM-001',
    'brand_id'    => 3,
    'categories'  => [5, 12],
]);

$newProduct = $response->getData();
```

**Accepted product fields:**

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Product name |
| `sku` | string | Stock keeping unit |
| `description` | string | Full HTML description |
| `short_description` | string | Excerpt |
| `price` | float | Base price |
| `cost_price` | float | Cost price |
| `sale_price` | float | Sale/discount price |
| `weight` | float | Weight |
| `width` | float | Width |
| `height` | float | Height |
| `depth` | float | Depth |
| `visible` | int | 1 = visible, 0 = hidden |
| `featured` | int | 1 = featured |
| `inventory` | int | Current stock level |
| `low_inventory` | int | Low stock warning threshold |
| `inventory_track` | int | 0 = none, 1 = product-level, 2 = variation-level |
| `brand_id` | int | Brand ID |
| `availability_id` | int | Availability ID |
| `condition` | string | Product condition |
| `search_keywords` | string | Additional search keywords |
| `sort_order` | int | Sort order |
| `page_title` | string | SEO page title |
| `meta_description` | string | SEO meta description |
| `warranty` | string | Warranty info |
| `allow_purchases` | int | 1 = purchasable |
| `free_shipping` | int | 1 = free shipping |
| `fixed_shipping` | float | Fixed shipping cost |
| `min_qty` | int | Minimum order quantity |
| `max_qty` | int | Maximum order quantity |
| `upc` | string | UPC code |
| `ean` | string | EAN code |
| `mpn` | string | Manufacturer part number |
| `gtin` | string | GTIN |
| `isbn` | string | ISBN |
| `categories` | array | Array of category IDs |

### Update a product

```php
$response = $client->products()->update(123, [
    'price'     => 79.90,
    'inventory' => 45,
]);
```

---

## Orders

### List orders

```php
$response = $client->orders()->list([
    'start'  => 0,
    'limit'  => 50,
    'status' => 11,  // Awaiting Fulfillment
]);

$orders = $response->getData();
$total = $response->getMeta()['total'];
```

### Get order count

```php
$response = $client->orders()->count();
$count = $response->getData()['count'];
```

### Get a single order

Orders are retrieved by their **order token** (not by numeric ID) for security. The token is a 32-character hex string returned when the order is created.

```php
$response = $client->orders()->get('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
$order = $response->getData();
// $order['products'] — line items
// $order['addresses'] — shipping/billing addresses
```

### Create an order

The API supports two pricing modes, determined by your API key's scopes:

**Catalog pricing** (default) — prices are calculated by the store based on the product catalog and the customer's group. You only need to send product IDs and quantities. Requires a `customer.id`.

```php
$response = $client->orders()->create([
    'customer' => [
        'id' => 42,
    ],
    'shipping_address' => [
        'firstname' => 'John',
        'lastname'  => 'Doe',
        'address1'  => '123 Main St',
        'city'      => 'Athens',
        'state'     => 'Attica',
        'country'   => 'GR',
        'zip'       => '10431',
        'phone'     => '+30 210 1234567',
    ],
    'items' => [
        ['product_id' => 101, 'quantity' => 2],
        ['product_id' => 205, 'quantity' => 1, 'variation_id' => 34],
    ],
]);

$orderId   = $response->getData()['order_id'];
$orderToken = $response->getData()['order_token'];  // store this!
```

**Custom pricing** (requires `orders.custom_pricing` scope) — you supply explicit prices per item. Used by marketplace integrations.

```php
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
    ],
    'shipping' => [
        'cost'        => 3.50,
        'description' => 'Standard Shipping',
    ],
    'items' => [
        [
            'product_id'        => 101,
            'quantity'          => 1,
            'unit_price'        => 29.90,
            'price_includes_vat' => true,
            'vat_ratio'         => 24.0,
        ],
    ],
]);
```

**Additional order fields:**

| Field | Type | Description |
|-------|------|-------------|
| `billing_address` | array | Same structure as shipping_address |
| `invoice_details` | array | `company`, `vat_number`, `doy`, `profession`, `address` |
| `payment_method` | string | Payment module identifier |
| `payment_display_name` | string | Human-readable payment method name |
| `customer_message` | string | Note from the customer |
| `channel_id` | int | Sales channel identifier |
| `channel_order_id` | string | External order reference |
| `currency_id` | int | Currency ID |

### Cancel an order

Requires both the order ID and the `order_token` received when the order was created. This prevents unauthorized cancellations.

```php
$response = $client->orders()->cancel(1025, 'abc123def456...');
// $response->getData()['old_status']
// $response->getData()['new_status']
```

Orders in the following statuses **cannot** be cancelled: Shipped, Partially Shipped, Refunded, Cancelled, Returned.

### Update order status

```php
$response = $client->orders()->updateStatus(1025, 11, 'Ready for shipping');
```

**Common status codes:**

| Code | Status |
|------|--------|
| 0 | Incomplete |
| 1 | Pending |
| 2 | Shipped |
| 4 | Refunded |
| 5 | Cancelled |
| 7 | Awaiting Payment |
| 9 | Awaiting Shipment |
| 11 | Awaiting Fulfillment |

---

## Error Handling

The SDK throws typed exceptions on API errors:

```php
use NagaCommerce\SDK\Exceptions\ApiException;
use NagaCommerce\SDK\Exceptions\AuthenticationException;
use NagaCommerce\SDK\Exceptions\ValidationException;

try {
    $response = $client->orders()->create($orderData);
} catch (AuthenticationException $e) {
    // 401 or 403 — invalid API key or insufficient scopes
    echo "Auth error: " . $e->getErrorDetail();
} catch (ValidationException $e) {
    // 422 — invalid order data
    echo "Validation error: " . $e->getErrorDetail();
} catch (ApiException $e) {
    // Any other API error
    echo "API error [{$e->getStatusCode()}]: " . $e->getMessage();
}
```

### Exception methods

| Method | Returns |
|--------|---------|
| `getStatusCode()` | HTTP status code (int) |
| `getErrorTitle()` | Error title from the API (string) |
| `getErrorDetail()` | Detailed error message (string) |
| `getMessage()` | Full formatted message (string) |

---

## Response Object

All SDK methods return a `NagaCommerce\SDK\Http\Response` object:

```php
$response = $client->products()->list();

$response->isSuccess();    // true if HTTP 2xx
$response->getStatusCode(); // e.g. 200
$response->getData();       // the "data" key from the JSON:API response
$response->getMeta();       // the "meta" key (pagination info, etc.)
$response->getErrors();     // the "errors" key (empty on success)
$response->getRawBody();    // the entire decoded JSON response
```

---

## Timeout

The default request timeout is 30 seconds. You can change it via the constructor:

```php
$client = new Client('https://your-store.com/api', 'your-api-key', 60);
```
