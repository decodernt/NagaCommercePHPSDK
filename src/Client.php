<?php

namespace NagaCommerce\SDK;

use NagaCommerce\SDK\Http\HttpClient;
use NagaCommerce\SDK\Resources\Products;
use NagaCommerce\SDK\Resources\Orders;

class Client
{
    private HttpClient $http;
    private ?Products $products = null;
    private ?Orders $orders = null;

    /**
     * @param string $baseUrl  Store API base URL (e.g. "https://store.example.com/api")
     * @param string $apiKey   API key for authentication
     * @param int    $timeout  Request timeout in seconds
     */
    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30)
    {
        $this->http = new HttpClient($baseUrl, $apiKey, $timeout);
    }

    public function products(): Products
    {
        if ($this->products === null) {
            $this->products = new Products($this->http);
        }
        return $this->products;
    }

    public function orders(): Orders
    {
        if ($this->orders === null) {
            $this->orders = new Orders($this->http);
        }
        return $this->orders;
    }

    public function getHttpClient(): HttpClient
    {
        return $this->http;
    }
}
