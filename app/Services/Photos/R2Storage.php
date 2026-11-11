<?php

namespace App\Services\Photos;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cloudflare R2 through its S3-compatible API, signed with AWS Signature V4.
 * Browsers and phones upload straight to R2 with a short-lived presigned URL, so photo files
 * never land on Bluehost and never count against its file limit.
 */
class R2Storage
{
    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('axiom.photos.r2'));
    }

    public function isConfigured(): bool
    {
        return filled($this->config['account_id'] ?? null) && filled($this->config['access_key'] ?? null)
            && filled($this->config['secret_key'] ?? null);
    }

    public function newKey(string $prefix, string $contentType): string
    {
        $ext = match ($contentType) {
            'image/png' => 'png',
            'image/heic' => 'heic',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return trim($prefix, '/').'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$ext;
    }

    public function uploadUrl(string $key, int $ttlSeconds = 900): string
    {
        return $this->presign('PUT', $key, $ttlSeconds);
    }

    public function viewUrl(string $key, int $ttlSeconds = 3600): string
    {
        return $this->presign('GET', $key, $ttlSeconds);
    }

    public function get(string $key): string
    {
        return Http::timeout(60)->get($this->presign('GET', $key, 300))->throw()->body();
    }

    public function delete(string $key): void
    {
        Http::timeout(30)->delete($this->presign('DELETE', $key, 300))->throw();
    }

    public function presign(string $method, string $key, int $ttlSeconds, ?\DateTimeInterface $now = null): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cloudflare R2 is not configured (R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY).');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $host = $this->config['account_id'].'.r2.cloudflarestorage.com';
        $region = 'auto';
        $service = 's3';
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $path = '/'.$this->config['bucket'].'/'.implode('/', array_map('rawurlencode', explode('/', $key)));

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->config['access_key'].'/'.$scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $ttlSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = implode("\n", [$method, $path, $canonicalQuery, "host:{$host}\n", 'host', 'UNSIGNED-PAYLOAD']);
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $kDate = hash_hmac('sha256', $date, 'AWS4'.$this->config['secret_key'], true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return "https://{$host}{$path}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }
}
