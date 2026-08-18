<?php
declare(strict_types=1);

namespace N3\Service;

/**
 * Validates the optional per-page feature image: an uploaded n3 image shown as a
 * faded backdrop behind the post header. Only paths produced by MediaService are
 * accepted, and only still images — a feature image is never a video.
 */
final class FeatureImageService
{
    public const MIN_OPACITY = 40;
    public const MAX_OPACITY = 60;
    public const DEFAULT_OPACITY = 50;

    private const PATH_PATTERN = '/^\/media\/[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif|bmp)$/D';

    /** A null or empty submission clears the current feature image. */
    public function clears(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** Returns the stored path, or null when the value is not an uploaded n3 image. */
    public function normalizePath(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return preg_match(self::PATH_PATTERN, $value) === 1 ? $value : null;
    }

    /** Clamps the requested opacity into the reviewed 40–60% band. */
    public function normalizeOpacity(mixed $value): int
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value))))) {
            return self::DEFAULT_OPACITY;
        }
        $opacity = (int)round((float)$value);
        return max(self::MIN_OPACITY, min(self::MAX_OPACITY, $opacity));
    }

    /** Reads a stored row into the projection/view shape used everywhere the header renders. */
    public function fromPage(array $page): ?array
    {
        $path = $this->normalizePath($page['feature_image'] ?? null);
        if ($path === null) return null;
        return ['url' => $path, 'opacity' => $this->normalizeOpacity($page['feature_image_opacity'] ?? self::DEFAULT_OPACITY)];
    }

    /** Builds the escaped custom-property declarations the stylesheet consumes. */
    public function styleAttribute(?array $featureImage): string
    {
        if ($featureImage === null) return '';
        $url = htmlspecialchars((string)$featureImage['url'], ENT_QUOTES, 'UTF-8');
        $opacity = (int)$featureImage['opacity'];
        return ' style="--feature-image: url(&quot;' . $url . '&quot;); --feature-image-opacity: ' . ($opacity / 100) . '"';
    }
}
