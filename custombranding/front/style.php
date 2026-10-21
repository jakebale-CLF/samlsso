<?php

/**
 * ------------------------------------------------------------------------
 * customBranding plugin for GLPI
 * ------------------------------------------------------------------------
 *
 * Serves the generated stylesheet for an entity. Public (see setup.php boot):
 * the root entity stylesheet is needed on the login page.
 */

use GlpiPlugin\Custombranding\Branding;
use GlpiPlugin\Custombranding\BrandingResolver;

$entities_id = (int) ($_GET['e'] ?? 0);

if (!Branding::canViewEntityBranding($entities_id)) {
    http_response_code(403);
    return;
}

$effective = Branding::getEffective($entities_id);
$css       = BrandingResolver::buildCss($effective['values'], Branding::getEffectiveUrls($entities_id));

header('Content-Type: text/css; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($_GET['v'] ?? '') === $effective['version']) {
    // The URL changes whenever the branding changes, so it can be cached for long.
    header('Cache-Control: ' . ($entities_id === 0 ? 'public' : 'private') . ', max-age=31536000, immutable');
} else {
    header('Cache-Control: no-cache');
}
echo $css;
