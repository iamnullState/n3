<?php
declare(strict_types=1);

namespace N3\View;

final class PageInformationView
{
    public static function render(array $information): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $author = is_array($information['author'] ?? null) ? $information['author'] : [];
        $name = (string)($author['name'] ?? 'Unknown author');
        $initial = mb_strtoupper(mb_substr($name, 0, 1)) ?: '?';
        $avatar = '<span class="page-info-avatar' . (!empty($author['avatar_url']) ? ' has-avatar' : '') . '">';
        if (!empty($author['avatar_url'])) $avatar .= '<img src="' . $escape($author['avatar_url']) . '" alt="' . $escape($name) . ' avatar">';
        $avatar .= '<span aria-hidden="true">' . $escape($initial) . '</span></span>';
        $authorBody = $avatar . '<strong>' . $escape($name) . '</strong>';
        $authorHtml = !empty($author['profile_url'])
            ? '<a class="page-info-author" href="' . $escape($author['profile_url']) . '">' . $authorBody . '</a>'
            : '<span class="page-info-author">' . $authorBody . '</span>';

        $items = self::item('Words', number_format((int)($information['word_count'] ?? 0)));
        $items .= self::dateItem('Created', $information['created_at'] ?? null, $escape);
        if (($information['first_published_at'] ?? null) !== null) {
            $items .= self::dateItem('First published', $information['first_published_at'], $escape);
        }
        $items .= self::dateItem('Updated', $information['updated_at'] ?? null, $escape);
        foreach (is_array($information['plugin_rows'] ?? null) ? $information['plugin_rows'] : [] as $row) {
            if (!is_array($row)) continue;
            $label = (string)($row['label'] ?? '');
            $value = (string)($row['value'] ?? '');
            if ($label === '' || $value === '') continue;
            $items .= self::item($label, $value, (string)($row['plugin_name'] ?? 'Plugin'));
        }

        return '<section class="page-information" aria-labelledby="page-information-heading"><div class="page-information-heading"><span class="eyebrow">PAGE INFORMATION</span><h2 id="page-information-heading">Page information</h2></div><div class="page-information-grid"><div class="page-info-author-field"><span>Author</span>' . $authorHtml . '</div><dl>' . $items . '</dl></div></section>';
    }

    private static function item(string $label, string $value, ?string $source = null): string
    {
        $sourceHtml = $source === null ? '' : '<small>' . htmlspecialchars($source, ENT_QUOTES, 'UTF-8') . '</small>';
        return '<div><dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $sourceHtml . '</dt><dd>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</dd></div>';
    }

    private static function dateItem(string $label, mixed $value, callable $escape): string
    {
        if (!is_string($value) || $value === '') return self::item($label, 'Unavailable');
        $timestamp = strtotime(preg_match('/(?:Z|[+-]\d\d:\d\d)$/iD', $value) ? $value : $value . ' UTC');
        if ($timestamp === false) return self::item($label, 'Unavailable');
        return '<div><dt>' . $label . '</dt><dd><time datetime="' . $escape($value) . '">' . gmdate('M j, Y', $timestamp) . '</time></dd></div>';
    }
}
