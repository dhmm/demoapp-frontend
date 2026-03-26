<?php

namespace App\Service;

use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ApiClient
 *
 * Wrapper around Symfony's HttpClient for communicating with the
 * Laravel REST API backend. Handles JWT token injection, error
 * normalisation, and JSON decoding.
 */
class ApiClient
{
    private const TIMEOUT = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SessionAuthService  $sessionAuth,
        private readonly string              $baseUrl,
    ) {}

    // -------------------------------------------------------------------------
    // Public API (no authentication required)
    // -------------------------------------------------------------------------

    /**
     * Perform an authenticated or unauthenticated GET request.
     *
     * @param  string  $path    e.g. '/products'
     * @param  array   $query   Query parameters
     * @return array
     * @throws \RuntimeException on HTTP error
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * Perform a POST request (unauthenticated).
     *
     * @param  string  $path
     * @param  array   $body
     * @return array
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    // -------------------------------------------------------------------------
    // Authenticated API calls (JWT injected automatically)
    // -------------------------------------------------------------------------

    /**
     * Authenticated GET request.
     *
     * @param  string  $path
     * @param  array   $query
     * @return array
     */
    public function authenticatedGet(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query], authenticated: true);
    }

    /**
     * Authenticated POST request.
     *
     * @param  string  $path
     * @param  array   $body
     * @return array
     */
    public function authenticatedPost(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body], authenticated: true);
    }

    /**
     * Authenticated PUT request.
     *
     * @param  string  $path
     * @param  array   $body
     * @return array
     */
    public function authenticatedPut(string $path, array $body): array
    {
        return $this->request('PUT', $path, ['json' => $body], authenticated: true);
    }

    /**
     * Authenticated PATCH request.
     *
     * @param  string  $path
     * @param  array   $body
     * @return array
     */
    public function authenticatedPatch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, ['json' => $body], authenticated: true);
    }

    /**
     * Authenticated DELETE request.
     *
     * @param  string  $path
     * @return array|null
     */
    public function authenticatedDelete(string $path): ?array
    {
        try {
            return $this->request('DELETE', $path, [], authenticated: true);
        } catch (\RuntimeException $e) {
            // 204 No Content is not an error
            if (str_contains($e->getMessage(), '204')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Authenticated GET that returns the raw response body (e.g. PDF download).
     *
     * @param  string  $path
     * @return string  Raw bytes
     */
    public function authenticatedGetRaw(string $path): string
    {
        $token = $this->sessionAuth->getToken();

        $response = $this->httpClient->request('GET', $this->buildUrl($path), [
            'timeout' => 30,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Accept'        => '*/*',
            ],
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException("API error {$response->getStatusCode()} on {$path}");
        }

        return $response->getContent();
    }

    /**
     * Upload a file with multipart form data.
     *
     * @param  string  $path
     * @param  array   $fields  Non-file fields
     * @param  array   $files   ['field_name' => UploadedFile]
     * @return array
     */
    public function authenticatedUpload(string $path, array $fields, array $files): array
    {
        $token = $this->sessionAuth->getToken();

        $formData = [];
        foreach ($fields as $key => $value) {
            $formData[] = ['name' => $key, 'value' => (string) $value];
        }
        foreach ($files as $fieldName => $file) {
            $formData[] = [
                'name'     => $fieldName,
                'value'    => fopen($file->getRealPath(), 'r'),
                'filename' => $file->getClientOriginalName(),
            ];
        }

        return $this->request('POST', $path, [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'body'    => $formData,
        ]);
    }

    // -------------------------------------------------------------------------
    // Core request method
    // -------------------------------------------------------------------------

    /**
     * Execute an HTTP request and return the decoded JSON body.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array   $options
     * @param  bool    $authenticated
     * @return array
     * @throws \RuntimeException on non-2xx responses
     */
    private function request(string $method, string $path, array $options = [], bool $authenticated = false): array
    {
        $options['timeout'] ??= self::TIMEOUT;
        $options['headers'] ??= [];

        $options['headers']['Accept']       = 'application/json';
        $options['headers']['Content-Type'] = 'application/json';

        if ($authenticated) {
            $token = $this->sessionAuth->getToken();
            if ($token) {
                $options['headers']['Authorization'] = "Bearer {$token}";
            }
        }

        try {
            $response   = $this->httpClient->request($method, $this->buildUrl($path), $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 204) {
                return [];
            }

            $data = $response->toArray(false);

            if ($statusCode >= 400) {
                $message = $data['message'] ?? "HTTP {$statusCode} error on {$method} {$path}";
                throw new \RuntimeException($message, $statusCode);
            }

            return $data;

        } catch (ClientException | ServerException $e) {
            $message = "API request failed: {$e->getMessage()}";
            throw new \RuntimeException($message, $e->getCode(), $e);
        }
    }

    private function buildUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/api/v1' . $path;
    }
}
