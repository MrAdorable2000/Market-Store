<?php
/** Real YouTube video helpers for IsokoRyacu. */
if (!function_exists('youtube_extract_id')) {
    function youtube_extract_id(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = trim($parts['path'] ?? '', '/');
        $id = null;
        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            $id = explode('/', $path)[0] ?? null;
        } elseif (str_contains($host, 'youtube.com')) {
            if (($parts['query'] ?? '') !== '') {
                parse_str($parts['query'], $q);
                $id = $q['v'] ?? null;
            }
            if (!$id && preg_match('~^(?:embed|shorts|live)/([^/?]+)~', $path, $m)) $id = $m[1];
        }
        return ($id && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) ? $id : null;
    }
}

if (!function_exists('youtube_embed_url')) {
    function youtube_embed_url(string $videoId): string
    {
        return 'https://www.youtube.com/embed/' . rawurlencode($videoId) . '?rel=0&modestbranding=1';
    }
}
