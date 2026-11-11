<?php

namespace App\Services\RentManager;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Rent Manager 12 Web API.
 *
 * Auth: POST /Authentication/AuthorizeUser with Username, Password, LocationID returns a token,
 * which is sent on later calls in the X-RM12Api-ApiToken header. Tokens are cached per location
 * and refreshed once on a 401.
 */
class RentManagerClient
{
    public function __construct(private readonly array $config = []) {}

    public static function fromConfig(): self
    {
        return new self(config('axiom.rent_manager'));
    }

    public function isConfigured(): bool
    {
        return filled($this->config['username'] ?? null) && filled($this->config['password'] ?? null);
    }

    public function token(int $rmLocationId, bool $fresh = false): string
    {
        $key = "rm_token_{$rmLocationId}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes($this->config['token_ttl_minutes'] ?? 50), function () use ($rmLocationId) {
            if (! $this->isConfigured()) {
                throw new RuntimeException('Rent Manager credentials are not set (RM_API_USER / RM_API_PASSWORD).');
            }
            $response = $this->http()->post($this->endpoint('auth'), [
                'Username' => $this->config['username'],
                'Password' => $this->config['password'],
                'LocationID' => $rmLocationId,
            ]);
            if (! $response->successful()) {
                throw new RuntimeException("Rent Manager login failed for location {$rmLocationId}: HTTP {$response->status()}");
            }

            return trim((string) $response->json() ?: $response->body(), "\" \n");
        });
    }

    public function get(int $rmLocationId, string $path, array $query = []): array
    {
        return $this->send($rmLocationId, 'get', $path, $query)->json() ?? [];
    }

    public function post(int $rmLocationId, string $path, array $body): array
    {
        return $this->send($rmLocationId, 'post', $path, $body)->json() ?? [];
    }

    /** Service issues changed since a time. Filter syntax follows RM12: Field,operator,value. */
    public function serviceIssuesSince(int $rmLocationId, ?string $sinceIso): array
    {
        $query = ['embeds' => 'Property,Unit,Tenant,Category,Priority', 'pagesize' => 200];
        if ($sinceIso) {
            $query['filters'] = "UpdateDate,gt,{$sinceIso}";
        }

        return $this->get($rmLocationId, $this->endpoint('issues'), $query);
    }

    /** Create or update a service issue. RM12 saves by POSTing an array of entities. */
    public function saveServiceIssue(int $rmLocationId, array $issue): array
    {
        $saved = $this->post($rmLocationId, $this->endpoint('issues'), [$issue]);

        return $saved[0] ?? $saved;
    }

    public function uploadIssueAttachment(int $rmLocationId, int $issueId, string $filename, string $contents, string $contentType): array
    {
        $path = str_replace('{id}', (string) $issueId, $this->endpoint('issue_attachments'));

        $response = $this->authed($rmLocationId)
            ->attach('file', $contents, $filename, ['Content-Type' => $contentType])
            ->post($path);
        if ($response->status() === 401) {
            $response = $this->authed($rmLocationId, true)
                ->attach('file', $contents, $filename, ['Content-Type' => $contentType])
                ->post($path);
        }
        $response->throw();

        return $response->json() ?? [];
    }

    private function send(int $rmLocationId, string $method, string $path, array $data): Response
    {
        $response = $this->authed($rmLocationId)->{$method}($path, $data);
        if ($response->status() === 401) {
            $response = $this->authed($rmLocationId, true)->{$method}($path, $data);
        }

        return $response->throw();
    }

    private function authed(int $rmLocationId, bool $fresh = false): PendingRequest
    {
        return $this->http()->withHeaders(['X-RM12Api-ApiToken' => $this->token($rmLocationId, $fresh)]);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->config['base_url'] ?? '', '/'))
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500, throw: false);
    }

    private function endpoint(string $name): string
    {
        return $this->config['endpoints'][$name] ?? throw new RuntimeException("Unknown Rent Manager endpoint {$name}");
    }
}
