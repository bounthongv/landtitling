<?php
// lib/i18n.php — lightweight bilingual (LO/EN) helper for the back-office.
//
// All UI text lives in /lang/lo.json (a flat map: English source string => Lao).
// Officers/admins can edit that file with any text editor — no code changes needed.
//
// Lao is the default. A `lang` cookie remembers the choice; the sidebar toggle
// (EN | ລາວ) flips it through /public/admin/lang.php.
//
// Usage in views (inside a PHP block):
//   echo '<h3>' . te('Confirm / Edit Parcel Data') . '</h3>';
//   echo '<label>' . te('Province') . '</label>';
//   echo '<p>' . te('{n} parcel(s)', ['n' => $count]) . '</p>';

// Resolve current language from the `lang` cookie (lo = Lao default, en = English).
function i18n_current() {
    static $lang = null;
    if ($lang !== null) return $lang;
    $lang = $_COOKIE['lang'] ?? 'lo';
    if (!in_array($lang, ['lo', 'en'], true)) $lang = 'lo';
    return $lang;
}

// Load the Lao translation map once per request.
function i18n_map() {
    static $map = null;
    if ($map !== null) return $map;
    $path = dirname(__DIR__) . '/lang/lo.json';
    $map = [];
    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) $map = $decoded;
    }
    return $map;
}

/**
 * Translate a UI string. Returns the Lao text when a translation exists and the
 * current language is Lao; otherwise returns the English source. Placeholders
 * like {name} are substituted from $vars in both languages.
 */
function t($key, array $vars = []) {
    $out = (i18n_current() === 'en') ? (string)$key : (i18n_map()[$key] ?? (string)$key);
    if ($vars) {
        $repl = [];
        foreach ($vars as $k => $v) { $repl['{' . $k . '}'] = (string)$v; }
        $out = strtr($out, $repl);
    }
    return $out;
}

// Short alias for use inside HTML attributes/JS strings.
function te($key, array $vars = []) {
    return htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');
}

/**
 * Sidebar language toggle. $next is the page to return to after switching;
 * defaults to the current admin page (path + query).
 */
function lang_switch($next = null) {
    if ($next === null) {
        $next = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
        if (!empty($_SERVER['QUERY_STRING'])) { $next .= '?' . $_SERVER['QUERY_STRING']; }
    }
    $next = (string)$next;
    $href = 'lang.php?next=' . urlencode($next) . '&lang=';
    $cur  = i18n_current();
    $en   = '<a class="' . ($cur === 'en' ? 'active' : '') . '" href="' . htmlspecialchars($href . 'en', ENT_QUOTES, 'UTF-8') . '">EN</a>';
    $lo   = '<a class="' . ($cur === 'lo' ? 'active' : '') . '" href="' . htmlspecialchars($href . 'lo', ENT_QUOTES, 'UTF-8') . '">ລາວ</a>';
    return '<div class="lang-switch">' . $en . '<span>|</span>' . $lo . '</div>';
}
