<?php
/**
 * includes/i18n.php
 * --------------------------------------------------------------------
 * Internationalization (i18n) helper for Isoko Ryacu.
 *
 *   current_lang()   → 'en' | 'rw' | 'fr' | 'sw'
 *   set_lang($code)  → persist selection in session + cookie
 *   available_langs() → array of available language codes + meta
 *   t($key, $params) → translate a key in the current language
 *
 * Language resolution order:
 *   1. ?lang=xx in the URL query (sets session + cookie)
 *   2. $_SESSION['lang']
 *   3. $_COOKIE['isoko_lang']
 *   4. default = 'en'
 *
 * Translation files live in /lang/{en,rw,fr,sw}.php and return an array
 * of key → string pairs.
 *
 * Usage:
 *   require_once __DIR__ . '/includes/i18n.php';
 *   echo t('nav.home');                  // "Home" (or "Ahabanza" etc.)
 *   echo t('flash.welcome_back', ['name' => 'Aline']);  // "Welcome back, Aline!"
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
start_session();

define('DEFAULT_LANG', 'en');
define('LANG_COOKIE',  'isoko_lang');
define('LANG_LIFETIME', 60 * 60 * 24 * 365);  // 1 year

/**
 * Map of available languages: code → [name, flag, native, file]
 */
function available_langs(): array
{
    return [
        'en' => ['name' => 'English',     'flag' => '🇬🇧', 'native' => 'English',     'file' => __DIR__ . '/../lang/en.php'],
        'rw' => ['name' => 'Kinyarwanda', 'flag' => '🇷🇼', 'native' => 'Kinyarwanda', 'file' => __DIR__ . '/../lang/rw.php'],
        'fr' => ['name' => 'Français',    'flag' => '🇫🇷', 'native' => 'Français',    'file' => __DIR__ . '/../lang/fr.php'],
        'sw' => ['name' => 'Kiswahili',  'flag' => '🇹🇿', 'native' => 'Kiswahili',  'file' => __DIR__ . '/../lang/sw.php'],
    ];
}

/**
 * Get the current language code (e.g. 'en', 'rw', 'fr', 'sw').
 * Falls back to 'en' if the requested language is unavailable.
 */
function current_lang(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $langs = available_langs();

    // 1. URL ?lang=xx (highest priority — overrides everything)
    if (isset($_GET['lang']) && isset($langs[$_GET['lang']])) {
        set_lang($_GET['lang']);
    }

    // 2. Session
    if (!empty($_SESSION['lang']) && isset($langs[$_SESSION['lang']])) {
        return $cached = $_SESSION['lang'];
    }

    // 3. Cookie
    if (!empty($_COOKIE[LANG_COOKIE]) && isset($langs[$_COOKIE[LANG_COOKIE]])) {
        $_SESSION['lang'] = $_COOKIE[LANG_COOKIE];
        return $cached = $_COOKIE[LANG_COOKIE];
    }

    // 4. Browser Accept-Language header (best-effort)
    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $pref = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));
        $map = ['en' => 'en', 'rw' => 'rw', 'fr' => 'fr', 'sw' => 'sw'];
        if (isset($map[$pref])) {
            $_SESSION['lang'] = $map[$pref];
            return $cached = $map[$pref];
        }
    }

    // 5. Default
    $_SESSION['lang'] = DEFAULT_LANG;
    return $cached = DEFAULT_LANG;
}

/**
 * Persist the selected language in session + cookie.
 */
function set_lang(string $code): void
{
    $langs = available_langs();
    if (!isset($langs[$code])) return;

    $_SESSION['lang'] = $code;
    setcookie(LANG_COOKIE, $code, [
        'expires'  => time() + LANG_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

/**
 * Cached translation array for the current language.
 */
function _load_translations(): array
{
    static $cache = [];
    $lang = current_lang();
    if (isset($cache[$lang])) return $cache[$lang];

    $file = available_langs()[$lang]['file'];
    if (!file_exists($file)) {
        // Fall back to English
        $file = available_langs()[DEFAULT_LANG]['file'];
    }
    $cache[$lang] = include $file;
    if (!is_array($cache[$lang])) $cache[$lang] = [];
    return $cache[$lang];
}

/**
 * Translate a key in the current language.
 *
 *   t('nav.home')                        → "Home"
 *   t('flash.welcome_back', ['name' => 'Aline'])
 *                                        → "Welcome back, Aline!"
 *
 * If the key is missing in the current language, falls back to English.
 * If still missing, returns the key itself (so the developer can spot it).
 *
 * @param string $key    Translation key (dotted notation)
 * @param array  $params Optional substitutions: {placeholder} → value
 */
function t(string $key, array $params = []): string
{
    static $enCache = null;
    $translations = _load_translations();

    // Look up in current language
    $value = $translations[$key] ?? null;

    // Fallback to English
    if ($value === null) {
        if ($enCache === null) {
            $enFile = available_langs()[DEFAULT_LANG]['file'];
            $enCache = file_exists($enFile) ? (include $enFile) : [];
            if (!is_array($enCache)) $enCache = [];
        }
        $value = $enCache[$key] ?? null;
    }

    // Still missing → return the key (signals a missing translation)
    if ($value === null) return $key;

    // Substitute {placeholder} params
    if ($params) {
        foreach ($params as $k => $v) {
            $value = str_replace('{' . $k . '}', (string) $v, $value);
        }
    }
    return (string) $value;
}

/**
 * Echo a translated key (helper for templates).
 */
function _t(string $key, array $params = []): void
{
    echo t($key, $params);
}

/**
 * Get the flag emoji + native name for the current language.
 * Used by the language selector button.
 */
function current_lang_meta(): array
{
    $langs = available_langs();
    $code = current_lang();
    return [
        'code'   => $code,
        'name'   => $langs[$code]['name'],
        'flag'   => $langs[$code]['flag'],
        'native' => $langs[$code]['native'],
    ];
}

/**
 * Translate a category name_key from the database.
 * Database stores keys like 'cat.vehicles'; we look them up in the lang file.
 */
function t_category(?string $nameKey, string $fallbackName = ''): string
{
    if (!$nameKey) return $fallbackName;
    $translated = t($nameKey);
    // If t() returned the key itself (missing), fall back to the DB name
    return $translated === $nameKey ? $fallbackName : $translated;
}

/**
 * Build the URL for switching language while preserving the current page.
 *   lang_switch_url('rw')  → "/pages/explore.php?lang=rw&q=iphone"
 */
function lang_switch_url(string $code): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    // Strip any existing ?lang= param
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/';
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $query['lang'] = $code;
    return $path . '?' . http_build_query($query);
}

// Initialize the language on every page load
current_lang();
