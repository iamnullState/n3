<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Repository\AppSettingsRepository;

final class AppSettingsService
{
    public const TOKEN_DEFAULTS = [
        'light' => [
            'bg' => '#e0e1dd', 'surface' => '#f3f4f1', 'sidebar' => '#d7d9d4', 'raised' => '#f8f9f7',
            'text' => '#0d1b2a', 'muted' => '#415a77', 'line' => '#b3bcc6', 'accent' => '#415a77',
            'accent-soft' => '#d3dae3', 'accent-strong' => '#1b263b',
        ],
        'dark' => [
            'bg' => '#0d1b2a', 'surface' => '#101f2f', 'sidebar' => '#0a1622', 'raised' => '#1b263b',
            'text' => '#e0e1dd', 'muted' => '#a9b8c9', 'line' => '#2b3a4f', 'accent' => '#778da9',
            'accent-soft' => '#1b263b', 'accent-strong' => '#a8bfd8',
        ],
    ];

    public function __construct(
        private readonly AppSettingsRepository $settings,
        private readonly string $dataDirectory,
    ) {}

    public function all(): array
    {
        $stored = $this->settings->all();
        return [
            'brandName' => $stored['brand_name'] ?? (getenv('APP_NAME') ?: 'n3'),
            'appUrl' => $stored['app_url'] ?? (getenv('APP_URL') ?: 'http://localhost:8786'),
            'tailscaleIp' => $stored['tailscale_ip'] ?? (getenv('APP_BIND_IP') ?: ''),
            'port' => (int)($stored['port'] ?? (getenv('APP_PORT') ?: 8786)),
            'iconUrl' => is_file($this->brandPath('icon')) ? '/brand/icon' : null,
            'bannerUrl' => is_file($this->brandPath('banner')) ? '/brand/banner' : null,
            'themes' => [
                'light' => $this->theme($stored, 'light'),
                'dark' => $this->theme($stored, 'dark'),
            ],
        ];
    }

    public function update(array $input): array
    {
        $current = $this->all();
        $brandName = $this->boundedText($input['brandName'] ?? $input['brand_name'] ?? $current['brandName'], 80);
        if ($brandName === '') throw new DomainException('Brand name is required.', 422);
        $tailscaleIp = trim((string)($input['tailscaleIp'] ?? $input['tailscale_ip'] ?? $current['tailscaleIp']));
        if ($tailscaleIp !== '' && filter_var($tailscaleIp, FILTER_VALIDATE_IP) === false) {
            throw new DomainException('Enter a valid Tailscale IP address.', 422);
        }
        $port = filter_var($input['port'] ?? $current['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new DomainException('Port must be between 1 and 65535.', 422);
        $appUrl = trim((string)($input['appUrl'] ?? $input['app_url'] ?? ''));
        if ($appUrl === '') $appUrl = 'http://' . ($tailscaleIp !== '' ? $tailscaleIp : 'localhost') . ':' . $port;
        if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new DomainException('Application URL must be a complete http or https URL.', 422);
        }

        $values = [
            'brand_name' => $brandName,
            'tailscale_ip' => $tailscaleIp,
            'port' => (string)$port,
            'app_url' => rtrim($appUrl, '/'),
        ];
        foreach (['light', 'dark'] as $mode) {
            $provided = is_array($input['themes'][$mode] ?? null) ? $input['themes'][$mode] : [];
            foreach (self::TOKEN_DEFAULTS[$mode] as $token => $fallback) {
                $color = strtolower(trim((string)($provided[$token] ?? $current['themes'][$mode][$token] ?? $fallback)));
                if (!preg_match('/^#[0-9a-f]{6}$/D', $color)) throw new DomainException("Invalid $mode theme color for $token.", 422);
                $values["theme_{$mode}_{$token}"] = $color;
            }
        }
        $this->settings->setMany($values);
        return $this->all();
    }

    public function storeBrandAsset(string $kind, array $upload): array
    {
        if (!in_array($kind, ['icon', 'banner'], true)) throw new DomainException('Unknown brand asset.', 404);
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
            throw new DomainException('Choose an image to upload.', 422);
        }
        if ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new DomainException('Brand images must be 5 MB or smaller.', 413);
        $info = @getimagesize((string)$upload['tmp_name']);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new DomainException('Use a JPEG, PNG, GIF, or WebP image.', 415);
        }
        $directory = $this->dataDirectory . '/branding';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new \RuntimeException('Could not create branding storage.');
        $target = $this->brandPath($kind);
        if (!move_uploaded_file((string)$upload['tmp_name'], $target)) throw new \RuntimeException('Could not store the brand image.');
        chmod($target, 0640);
        return $this->all();
    }

    public function brandAsset(string $kind): ?array
    {
        if (!in_array($kind, ['icon', 'banner'], true)) return null;
        $path = $this->brandPath($kind);
        if (!is_file($path)) return null;
        $mime = (string)(@mime_content_type($path) ?: 'application/octet-stream');
        if (!str_starts_with($mime, 'image/')) return null;
        return ['path' => $path, 'mime' => $mime, 'size' => filesize($path) ?: 0];
    }

    private function theme(array $stored, string $mode): array
    {
        $theme = [];
        foreach (self::TOKEN_DEFAULTS[$mode] as $token => $fallback) $theme[$token] = $stored["theme_{$mode}_{$token}"] ?? $fallback;
        return $theme;
    }

    private function brandPath(string $kind): string
    {
        return $this->dataDirectory . '/branding/' . $kind;
    }

    private function boundedText(mixed $value, int $length): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$value) ?? ''), 0, $length);
    }
}
