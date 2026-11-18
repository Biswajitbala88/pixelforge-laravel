<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Thin HTTP wrapper. All non-2xx responses become a PixelforgeApiException. */
class PixelforgeClient
{
    /** Fields POST /v1/generations accepts. Anything else is a 422 at Pixelforge. */
    private const CREATE_FIELDS = ['prompt', 'size', 'quality', 'reference_images', 'metadata'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed> The generation object.
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        $body = array_intersect_key($payload, array_flip(self::CREATE_FIELDS));

        if (empty($body['reference_images'])) {
            unset($body['reference_images']);
        }

        return $this->send(fn () => $this->authed()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/v1/generations', $body));
    }

    /** @return array<string,mixed> */
    public function getGeneration(string $pixelforgeId): array
    {
        return $this->send(fn () => $this->authed()->get('/v1/generations/'.rawurlencode($pixelforgeId)));
    }

    /**
     * @param  array<string,string>  $query
     * @return array<string,mixed>
     */
    public function usage(array $query = []): array
    {
        return $this->send(fn () => $this->authed()->get('/v1/usage', $query));
    }

    /** Public endpoint, no auth. @return array<string,mixed> */
    public function pricing(): array
    {
        return $this->send(fn () => $this->base()->get('/v1/pricing'));
    }

    private function base(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->timeout($this->timeout)->connectTimeout(5);
    }

    private function authed(): PendingRequest
    {
        return $this->base()->withToken((string) $this->apiKey);
    }

    /** @param callable(): Response $request */
    private function send(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new PixelforgeApiException(0, 'connection_error', 'Could not reach Pixelforge: '.$e->getMessage(), null, $e);
        }

        if ($response->successful()) {
            $json = $response->json();

            // Some APIs wrap the object; accept both {"id":...} and {"data":{...}}.
            return is_array($json) ? (isset($json['data']) && is_array($json['data']) && isset($json['data']['id']) ? $json['data'] : $json) : [];
        }

        $error = $response->json('error');
        $code = is_array($error) ? ($error['code'] ?? $error['type'] ?? null) : (is_string($error) ? $error : null);
        $code ??= $response->json('code') ?? 'unknown';
        $message = (is_array($error) ? ($error['message'] ?? null) : null) ?? $response->json('message') ?? "Pixelforge returned HTTP {$response->status()}";

        throw new PixelforgeApiException(
            $response->status(),
            (string) $code,
            (string) $message,
            self::parseRetryAfter($response->header('Retry-After')),
        );
    }

    private static function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || $header === '') {
            return null;
        }

        if (ctype_digit(trim($header))) {
            return (int) trim($header);
        }

        $ts = strtotime($header); // HTTP-date form

        return $ts ? max(0, $ts - time()) : null;
    }
}
