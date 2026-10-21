<?php

/**
 * Standalone tests for the GLPI independent branding logic.
 *
 * Run: php tests/BrandingResolverTest.php
 */

require_once __DIR__ . '/../src/BrandingResolver.php';

use GlpiPlugin\Custombranding\BrandingResolver as R;

$failures = 0;
$count    = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
        echo "FAIL: $name\n";
    }
}

// Colour normalisation.
check('short hex expands', R::normalizeColor('#AbC') === '#aabbcc');
check('hash optional', R::normalizeColor('112233') === '#112233');
check('invalid rejected', R::normalizeColor('red') === null);
check('css injection rejected', R::normalizeColor('#fff;}body{') === null);
check('empty is null', R::normalizeColor('') === null);

// Contrast.
check('white on dark', R::contrastColor('#000000') === '#ffffff');
check('dark on light', R::contrastColor('#ffffff') === '#1e293b');

// Inheritance: child (3) -> parent (2) -> root (0).
$rows = [
    0 => ['page_title' => 'Root', 'primary_color' => '#111111', 'logo_large' => 'root.png', 'custom_css' => ''],
    2 => ['page_title' => '', 'primary_color' => '#222222', 'custom_css' => '  '],
    3 => ['page_title' => 'Child'],
];
$r = R::resolve($rows, [3, 2, 0]);
check('own value wins', $r['values']['page_title'] === 'Child' && $r['sources']['page_title'] === 3);
check('parent value inherited', $r['values']['primary_color'] === '#222222' && $r['sources']['primary_color'] === 2);
check('root value inherited', $r['values']['logo_large'] === 'root.png' && $r['sources']['logo_large'] === 0);
check('blank values skipped', !isset($r['values']['custom_css']));
check('missing entity row tolerated', R::resolve([], [5, 0]) === ['values' => [], 'sources' => []]);

// MIME handling.
check('png allowed', R::extensionForMime('image/png') === 'png');
check('svg allowed', R::extensionForMime('image/svg+xml') === 'svg');
check('html refused', R::extensionForMime('text/html') === null);

// CSS sanitising.
check('import stripped', !str_contains(R::sanitizeCss('@import url(evil.css); a{}'), '@import url'));
check('expression stripped', !preg_match('/expression\s*\(/i', R::sanitizeCss('a{width:expression(alert(1))}')));
check('javascript stripped', !stripos(R::sanitizeCss('a{background:url(javascript:alert(1))}'), 'javascript:'));

// CSS generation.
$css = R::buildCss(
    ['primary_color' => '#ff0000', 'menu_bg_color' => '#000000', 'login_bg_color' => '#123456', 'custom_css' => '.x{color:red}'],
    ['logo_large' => '/p/r.php?e=1&f=logo_large', 'logo_login' => '/p/login"x.png', 'login_background' => '/bg.png']
);
check('primary var', str_contains($css, '--tblr-primary: #ff0000 !important;'));
check('primary rgb', str_contains($css, '--tblr-primary-rgb: 255, 0, 0 !important;'));
check('menu bg', str_contains($css, '--glpi-mainmenu-bg: #000000 !important;'));
check('menu fg auto contrast', str_contains($css, '--glpi-mainmenu-fg: #ffffff !important;'));
check('logo var', str_contains($css, '--glpi-logo: url("/p/r.php?e=1&f=logo_large") !important;'));
check('url quotes escaped', str_contains($css, 'url("/p/login\"x.png")'));
check('login background', str_contains($css, 'body.welcome-anonymous { background: url("/bg.png") center / cover no-repeat fixed #123456 !important; }'));
check('custom css appended last', str_ends_with(trim($css), '.x{color:red}'));
check('empty branding yields no rules', !str_contains(R::buildCss([], []), '{'));
check('invalid colour ignored in css', !str_contains(R::buildCss(['primary_color' => 'red;}'], []), 'red'));

// Fingerprint changes with content.
check('fingerprint stable', R::fingerprint(['a' => 1]) === R::fingerprint(['a' => 1]));
check('fingerprint changes', R::fingerprint(['a' => 1]) !== R::fingerprint(['a' => 2]));

echo sprintf("%d/%d checks passed\n", $count - $failures, $count);
exit($failures === 0 ? 0 : 1);
