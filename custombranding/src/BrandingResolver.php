<?php

/**
 * ------------------------------------------------------------------------
 * customBranding plugin for GLPI
 *
 * Per-entity white-labelling of the GLPI interface: logos, favicon,
 * login page, colours, page title and custom CSS.
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Custombranding;

/**
 * GLPI independent branding logic.
 *
 * Everything in here is pure (no database, no session, no globals) so it can
 * be unit tested outside of GLPI. The GLPI glue lives in Branding.
 */
final class BrandingResolver
{
    /** Image fields => human readable label. */
    public const FILE_FIELDS = [
        'logo_large'       => 'Main logo (expanded menu)',
        'logo_small'       => 'Reduced logo (collapsed menu)',
        'logo_login'       => 'Login page logo',
        'favicon'          => 'Favicon',
        'login_background' => 'Login page background image',
    ];

    /** Colour fields => human readable label. */
    public const COLOR_FIELDS = [
        'primary_color'  => 'Primary colour (buttons, links)',
        'menu_bg_color'  => 'Main menu background',
        'menu_fg_color'  => 'Main menu text',
        'login_bg_color' => 'Login page background colour',
    ];

    /** Free text fields. */
    public const TEXT_FIELDS = [
        'page_title' => 'Application name (browser tab title)',
        'custom_css' => 'Custom CSS',
    ];

    /** Accepted image MIME types => stored file extension. */
    public const ALLOWED_MIMES = [
        'image/png'                => 'png',
        'image/jpeg'               => 'jpg',
        'image/gif'                => 'gif',
        'image/webp'               => 'webp',
        'image/svg+xml'            => 'svg',
        'image/x-icon'             => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    /** Maximum accepted upload size in bytes. */
    public const MAX_UPLOAD_SIZE = 2 * 1024 * 1024;

    /**
     * Every field that participates in inheritance.
     *
     * @return string[]
     */
    public static function getInheritableFields(): array
    {
        return array_merge(
            array_keys(self::FILE_FIELDS),
            array_keys(self::COLOR_FIELDS),
            array_keys(self::TEXT_FIELDS)
        );
    }

    /**
     * Resolve the effective branding for an entity.
     *
     * Each field is taken from the closest entity in the chain that defines a
     * non-empty value, so a sub-entity only needs to override what differs
     * from its parent.
     *
     * @param array<int, array<string, mixed>> $rows  Branding rows keyed by entities_id.
     * @param int[]                            $chain Entity ids, the entity itself first, then its ancestors up to the root.
     * @return array{values: array<string, string>, sources: array<string, int>}
     *         values: field => effective value; sources: field => entity id that defined it.
     */
    public static function resolve(array $rows, array $chain): array
    {
        $values  = [];
        $sources = [];
        foreach (self::getInheritableFields() as $field) {
            foreach ($chain as $entities_id) {
                $value = $rows[$entities_id][$field] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $values[$field]  = $value;
                    $sources[$field] = (int) $entities_id;
                    break;
                }
            }
        }
        return ['values' => $values, 'sources' => $sources];
    }

    /**
     * Normalise a colour input to lowercase #rrggbb, or null when invalid/empty.
     */
    public static function normalizeColor(?string $color): ?string
    {
        $color = strtolower(trim((string) $color));
        if (preg_match('/^#?([0-9a-f]{3})$/', $color, $m)) {
            $c = $m[1];
            return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }
        if (preg_match('/^#?([0-9a-f]{6})$/', $color, $m)) {
            return '#' . $m[1];
        }
        return null;
    }

    /**
     * Pick a readable foreground (black or white) for a background colour.
     */
    public static function contrastColor(string $hex): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        // Relative luminance approximation (ITU-R BT.601).
        $luma = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luma > 0.6 ? '#1e293b' : '#ffffff';
    }

    /**
     * @return int[] [r, g, b]
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim((string) self::normalizeColor($hex), '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * Map a detected MIME type to the stored file extension, or null if refused.
     */
    public static function extensionForMime(string $mime): ?string
    {
        return self::ALLOWED_MIMES[strtolower($mime)] ?? null;
    }

    /**
     * Make admin supplied CSS safe to serve as a stylesheet.
     *
     * The CSS is served from its own text/css endpoint, so it cannot break out
     * into HTML. We still drop legacy script vectors that some browsers honour.
     */
    public static function sanitizeCss(string $css): string
    {
        $css = str_replace("\0", '', $css);
        $css = preg_replace('/expression\s*\(/i', '/* blocked */(', $css) ?? '';
        $css = preg_replace('/javascript\s*:/i', '/* blocked */', $css) ?? '';
        $css = preg_replace('/@import\b[^;]*;?/i', '/* @import blocked */', $css) ?? '';
        return trim($css);
    }

    /**
     * Build the stylesheet for resolved branding values.
     *
     * Core GLPI themes set their variables on ":root[data-glpi-theme=...]"
     * and the dark palettes use !important for the login logo, so every
     * override here is both more specific and !important.
     *
     * @param array<string, string> $values Resolved values (see resolve()).
     * @param array<string, string> $urls   Image field => public URL.
     */
    public static function buildCss(array $values, array $urls): string
    {
        $root  = 'html:root[data-glpi-theme]';
        $rules = [];
        $vars  = [];

        if (isset($urls['logo_large'])) {
            $url = self::cssUrl($urls['logo_large']);
            foreach (['--glpi-logo', '--glpi-logo-light', '--glpi-logo-dark'] as $var) {
                $vars[] = "$var: $url !important;";
            }
        }
        if (isset($urls['logo_small'])) {
            $url = self::cssUrl($urls['logo_small']);
            foreach (['--glpi-logo-reduced', '--glpi-logo-light-reduced', '--glpi-logo-dark-reduced'] as $var) {
                $vars[] = "$var: $url !important;";
            }
        }
        if (isset($urls['logo_large']) || isset($urls['logo_small'])) {
            // Custom logos rarely share GLPI's 100x55 ratio: scale them into the box.
            $rules[] = ".glpi-logo { background-size: contain !important; background-position: center !important; }";
        }

        $primary = self::normalizeColor($values['primary_color'] ?? null);
        if ($primary !== null) {
            [$r, $g, $b] = self::hexToRgb($primary);
            $vars[] = "--tblr-primary: $primary !important;";
            $vars[] = "--tblr-primary-rgb: $r, $g, $b !important;";
            $vars[] = "--tblr-primary-fg: " . self::contrastColor($primary) . " !important;";
            $vars[] = "--tblr-link-color-rgb: $r, $g, $b !important;";
        }

        $menuBg = self::normalizeColor($values['menu_bg_color'] ?? null);
        if ($menuBg !== null) {
            $vars[] = "--glpi-mainmenu-bg: $menuBg !important;";
            $vars[] = "--tblr-navbar-color: $menuBg !important;";
        }

        $menuFg = self::normalizeColor($values['menu_fg_color'] ?? null);
        if ($menuFg === null && $menuBg !== null) {
            $menuFg = self::contrastColor($menuBg);
        }
        if ($menuFg !== null) {
            $vars[] = "--glpi-mainmenu-fg: $menuFg !important;";
            $vars[] = "--glpi-mainmenu-fg-muted: {$menuFg}99 !important;";
        }

        if ($vars !== []) {
            array_unshift($rules, "$root {\n  " . implode("\n  ", $vars) . "\n}");
        }

        // Login (anonymous) pages.
        if (isset($urls['logo_login'])) {
            $rules[] = "$root body .page-anonymous .glpi-logo { --logo: " . self::cssUrl($urls['logo_login'])
                . " !important; object-fit: contain; }";
        }
        $loginBg = self::normalizeColor($values['login_bg_color'] ?? null);
        $loginBgImage = isset($urls['login_background'])
            ? self::cssUrl($urls['login_background']) . ' center / cover no-repeat fixed'
            : null;
        if ($loginBg !== null || $loginBgImage !== null) {
            $background = trim(($loginBgImage ?? '') . ' ' . ($loginBg ?? ''));
            $rules[] = "$root body.welcome-anonymous { background: $background !important; }";
        }

        $custom = self::sanitizeCss($values['custom_css'] ?? '');
        if ($custom !== '') {
            $rules[] = "/* Custom CSS */\n" . $custom;
        }

        return "/* Generated by the customBranding plugin */\n" . implode("\n\n", $rules) . "\n";
    }

    /**
     * Quote a URL for use inside url().
     */
    public static function cssUrl(string $url): string
    {
        return 'url("' . addcslashes($url, "\"\\\n\r") . '")';
    }

    /**
     * Short stable fingerprint of the resolved branding, used to bust caches.
     *
     * @param array<string, mixed> $resolved Output of resolve(), optionally with extra keys.
     */
    public static function fingerprint(array $resolved): string
    {
        return substr(sha1((string) json_encode($resolved)), 0, 12);
    }
}
