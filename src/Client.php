<?php

namespace NagaCommerce\SDK;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Resources\Brands;
use NagaCommerce\SDK\Resources\Categories;
use NagaCommerce\SDK\Resources\Coupons;
use NagaCommerce\SDK\Resources\Customers;
use NagaCommerce\SDK\Resources\Discounts;
use NagaCommerce\SDK\Resources\Documents;
use NagaCommerce\SDK\Resources\Export;
use NagaCommerce\SDK\Resources\Media;
use NagaCommerce\SDK\Resources\Modules;
use NagaCommerce\SDK\Resources\News;
use NagaCommerce\SDK\Resources\Orders;
use NagaCommerce\SDK\Resources\Pricelists;
use NagaCommerce\SDK\Resources\Products;
use NagaCommerce\SDK\Resources\Reference;
use NagaCommerce\SDK\Resources\Reviews;
use NagaCommerce\SDK\Resources\Sync;
use NagaCommerce\SDK\Resources\Webhooks;

/**
 * Entry point for the NagaCommerce SDK.
 *
 * One client, one base URL, one API key. Resources are lazy-loaded — they're
 * only instantiated on first access, so unused surfaces add no allocation
 * cost. Every resource shares the same HttpClient (and therefore the same
 * timeout + headers).
 *
 * Need direct access for a route the SDK doesn't expose yet? Reach in via
 * getHttpClient() — it has get/post/put/delete primitives you can call with
 * any path.
 */
class Client
{
    private HttpClient $http;

    private ?Products $products = null;
    private ?Orders $orders = null;
    private ?Brands $brands = null;
    private ?Categories $categories = null;
    private ?Customers $customers = null;
    private ?Pricelists $pricelists = null;
    private ?News $news = null;
    private ?Export $export = null;
    private ?Sync $sync = null;
    private ?Media $media = null;
    private ?Modules $modules = null;
    private ?Reference $reference = null;
    private ?Reviews $reviews = null;
    private ?Coupons $coupons = null;
    private ?Discounts $discounts = null;
    private ?Webhooks $webhooks = null;
    private ?Documents $documents = null;

    /**
     * @param string $baseUrl  Store API base URL (e.g. "https://store.example.com/api")
     * @param string $apiKey   API key for authentication
     * @param int    $timeout  Request timeout in seconds
     * @param array  $options  Optional HttpClient flags; see HttpClient::__construct
     */
    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30, array $options = [])
    {
        $this->http = new HttpClient($baseUrl, $apiKey, $timeout, $options);
    }

    public function products(): Products
    {
        return $this->products ??= new Products($this->http);
    }

    public function orders(): Orders
    {
        return $this->orders ??= new Orders($this->http);
    }

    public function brands(): Brands
    {
        return $this->brands ??= new Brands($this->http);
    }

    public function categories(): Categories
    {
        return $this->categories ??= new Categories($this->http);
    }

    public function customers(): Customers
    {
        return $this->customers ??= new Customers($this->http);
    }

    public function pricelists(): Pricelists
    {
        return $this->pricelists ??= new Pricelists($this->http);
    }

    public function news(): News
    {
        return $this->news ??= new News($this->http);
    }

    public function export(): Export
    {
        return $this->export ??= new Export($this->http);
    }

    public function sync(): Sync
    {
        return $this->sync ??= new Sync($this->http);
    }

    public function media(): Media
    {
        return $this->media ??= new Media($this->http);
    }

    public function modules(): Modules
    {
        return $this->modules ??= new Modules($this->http);
    }

    public function reference(): Reference
    {
        return $this->reference ??= new Reference($this->http);
    }

    public function reviews(): Reviews
    {
        return $this->reviews ??= new Reviews($this->http);
    }

    public function coupons(): Coupons
    {
        return $this->coupons ??= new Coupons($this->http);
    }

    public function discounts(): Discounts
    {
        return $this->discounts ??= new Discounts($this->http);
    }

    public function webhooks(): Webhooks
    {
        return $this->webhooks ??= new Webhooks($this->http);
    }

    public function documents(): Documents
    {
        return $this->documents ??= new Documents($this->http);
    }

    public function getHttpClient(): HttpClient
    {
        return $this->http;
    }
}
