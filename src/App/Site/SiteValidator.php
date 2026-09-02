<?php

declare(strict_types=1);

namespace N3\App\Site;

final class SiteValidator
{
    /** @return array<string, string> */
    public function identityErrors(string $name, string $tagline, string $email, string $color, string $logoPath): array
    {
        $errors = [];
        foreach (['site_name' => [$name, 2, 100], 'tagline' => [$tagline, 0, 200]] as $key => [$value, $min, $max]) {
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen(trim($value), 'UTF-8') < $min
                || mb_strlen(trim($value), 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
                $errors[$key] = $key === 'site_name'
                    ? 'Site name must be between 2 and 100 characters without control characters.'
                    : 'Tagline must be at most 200 characters without control characters.';
            }
        }
        $email = mb_strtolower(trim($email), 'UTF-8');
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['contact_email'] = 'Enter a valid contact email address.';
        }
        $color = strtoupper(trim($color));
        if (!preg_match('/^#[0-9A-F]{6}$/D', $color) || !$this->whiteContrastPasses($color)) {
            $errors['primary_color'] = 'Use a six-digit hex color with at least 4.5:1 contrast against white.';
        }
        $logoPath = trim($logoPath);
        if ($logoPath !== '' && (strlen($logoPath) > 255 || str_contains($logoPath, '..')
            || preg_match('#^/assets/(?:photos|svg)/(?:[A-Za-z0-9][A-Za-z0-9_-]*/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:svg|png|jpe?g|webp)$#iD', $logoPath) !== 1)) {
            $errors['logo_path'] = 'Logo path must be a same-site SVG, PNG, JPEG, or WebP under /assets/photos or /assets/svg.';
        }

        return $errors;
    }

    /** @param mixed $input @return array{items: list<array{page_id: int, label: string, position: int, visible: bool}>, errors: array<string, string>} */
    public function navigation(mixed $input): array
    {
        if (!is_array($input) || count($input) > 200) {
            return ['items' => [], 'errors' => ['navigation' => 'Navigation data is invalid or too large.']];
        }
        $items = [];
        $errors = [];
        $pageIds = [];
        $positions = [];
        foreach ($input as $key => $row) {
            $validKey = (is_int($key) && $key > 0)
                || (is_string($key) && ctype_digit($key) && (int) $key > 0);
            if (!$validKey || !is_array($row)) {
                $errors['navigation'] = 'Navigation contains an invalid Page reference.';
                continue;
            }
            $pageId = (int) $key;
            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';
            $positionRaw = $row['position'] ?? null;
            $position = is_string($positionRaw) && ctype_digit($positionRaw) ? (int) $positionRaw : 0;
            if (!mb_check_encoding($label, 'UTF-8') || mb_strlen($label, 'UTF-8') < 1 || mb_strlen($label, 'UTF-8') > 80
                || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1) {
                $errors['navigation_' . $pageId] = 'Each navigation label must be between 1 and 80 characters.';
            }
            if ($position < 1 || $position > 65535 || isset($positions[$position])) {
                $errors['navigation_' . $pageId] = 'Each navigation position must be unique and between 1 and 65535.';
            }
            if (isset($pageIds[$pageId])) {
                $errors['navigation'] = 'Navigation Page references must be unique.';
            }
            $pageIds[$pageId] = true;
            $positions[$position] = true;
            $items[] = [
                'page_id' => $pageId,
                'label' => $label,
                'position' => $position,
                'visible' => isset($row['visible']) && (string) $row['visible'] === '1',
            ];
        }

        return ['items' => $items, 'errors' => $errors];
    }

    private function whiteContrastPasses(string $color): bool
    {
        $channels = [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))];
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);
        $luminance = 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];

        return 1.05 / ($luminance + 0.05) >= 4.5;
    }
}
