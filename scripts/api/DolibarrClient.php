<?php

/**
 * Minimal Dolibarr REST API client for South Side Supplies website integration.
 *
 * Usage:
 *   $api = new DolibarrClient($_ENV['DOLIBARR_URL'], $_ENV['DOLIBARR_API_KEY']);
 *   $products = $api->get('products', ['limit' => 100]);
 *   $product  = $api->get('products/1');
 */
class DolibarrClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/api/index.php';
        $this->apiKey  = $apiKey;
    }

    /**
     * GET request. Returns decoded array or throws on error.
     *
     * @param  string $endpoint  e.g. 'products', 'products/1', 'orders'
     * @param  array  $params    additional query params
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * POST request. Returns decoded value (int ID or array) or throws on error.
     */
    public function post(string $endpoint, array $body): mixed
    {
        return $this->request('POST', $endpoint, [], $body);
    }

    /**
     * PUT request (update existing record).
     */
    public function put(string $endpoint, array $body): mixed
    {
        return $this->request('PUT', $endpoint, [], $body);
    }

    /**
     * DELETE request.
     */
    public function delete(string $endpoint): mixed
    {
        return $this->request('DELETE', $endpoint);
    }

    private function request(string $method, string $endpoint, array $params = [], ?array $body = null): mixed
    {
        $params['DOLAPIKEY'] = $this->apiKey;
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Dolibarr API cURL error: $err");
        }

        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) ? ($data['error']['message'] ?? $raw) : $raw;
            throw new RuntimeException("Dolibarr API error $code: $msg");
        }

        return $data;
    }
}
