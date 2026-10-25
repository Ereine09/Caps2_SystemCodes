<?php
/**
 * Installable apps ("Add to Home Screen"), like the rider portal (rider/manifest.json):
 *   admin -> DPS Admin  (assets/pwa/admin.json, opens modules/admin/dashboard.php)
 *   staff -> DPS Staff  (assets/pwa/staff.json, opens modules/staff/dashboard.php)
 *   shop  -> DPS Shop   (assets/pwa/shop.json,  opens customer/dashboard.php)
 * Installed, the portal opens full-screen with its own icon and name; the pages themselves are the
 * same as in the browser.
 */

const PWA_APPS = [
    'admin' => ['name' => 'DPS Admin', 'color' => '#4f46e5'],
    'staff' => ['name' => 'DPS Staff', 'color' => '#0f766e'],
    'shop'  => ['name' => 'DPS Shop',  'color' => '#e8590c'],
];

/** [tag, attributes] list for one app's <head>. */
function pwa_tag_list(string $app): array
{
    $cfg = PWA_APPS[$app] ?? PWA_APPS['shop'];
    $base = (defined('BASE_URL') ? BASE_URL : '') . '/assets/pwa/';
    $ver = @filemtime(__DIR__ . '/../../assets/pwa/' . $app . '.json') ?: 1;
    return [
        ['link', ['rel' => 'manifest', 'href' => $base . $app . '.json?v=' . $ver]],
        ['meta', ['name' => 'theme-color', 'content' => $cfg['color']]],
        ['meta', ['name' => 'application-name', 'content' => $cfg['name']]],
        ['meta', ['name' => 'mobile-web-app-capable', 'content' => 'yes']],
        ['meta', ['name' => 'apple-mobile-web-app-capable', 'content' => 'yes']],
        ['meta', ['name' => 'apple-mobile-web-app-title', 'content' => $cfg['name']]],
        ['meta', ['name' => 'apple-mobile-web-app-status-bar-style', 'content' => 'default']],
        ['link', ['rel' => 'apple-touch-icon', 'href' => $base . $app . '-180.png']],
    ];
}

/**
 * Registers /sw.js (site-wide scope). Older Chrome on Android only offers "Install app" for pages
 * controlled by a service worker; sw.js loads everything from the network (offline page when offline).
 */
function pwa_sw_register_js(): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    return "if('serviceWorker' in navigator){window.addEventListener('load',function(){navigator.serviceWorker.register("
        . json_encode($base . '/sw.js') . ",{scope:" . json_encode($base . '/') . "}).catch(function(){});});}";
}

/** The tags as HTML, for a page that has its own <head> (customer/includes/header.php). */
function pwa_head_tags(string $app): string
{
    $html = '';
    foreach (pwa_tag_list($app) as [$tag, $attrs]) {
        $html .= '<' . $tag;
        foreach ($attrs as $k => $v) $html .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '"';
        $html .= ">\n    ";
    }
    return rtrim($html) . "\n    <script>" . pwa_sw_register_js() . "</script>\n";
}

/**
 * The same tags added to <head> from a shared include that is printed inside <body> (the admin and
 * staff sidebars) - so all 40+ admin/staff pages get them without editing each page's own <head>.
 */
function pwa_head_script(string $app): string
{
    return '<script>(function(d){var t=' . json_encode(pwa_tag_list($app), JSON_UNESCAPED_SLASHES)
        . ';t.forEach(function(x){var a=x[1],sel=x[0]+(a.rel?\'[rel="\'+a.rel+\'"]\':\'[name="\'+a.name+\'"]\'),old=d.head.querySelector(sel);if(old)old.remove();'
        . 'var e=d.createElement(x[0]);Object.keys(a).forEach(function(k){e.setAttribute(k,a[k]);});d.head.appendChild(e);});})(document);' . pwa_sw_register_js() . '</script>' . "\n";
}
