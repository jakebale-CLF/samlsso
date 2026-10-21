<?php

/**
 * ------------------------------------------------------------------------
 * customBranding plugin for GLPI
 * ------------------------------------------------------------------------
 *
 * Serves a branding image (logo, favicon, background) as resolved for an
 * entity, inheritance included. Public (see setup.php boot): the root entity
 * images are needed on the login page.
 */

use GlpiPlugin\Custombranding\Branding;
use GlpiPlugin\Custombranding\BrandingResolver;

$entities_id = (int) ($_GET['e'] ?? 0);
$field       = (string) ($_GET['f'] ?? '');

if (!array_key_exists($field, BrandingResolver::FILE_FIELDS) || !Branding::canViewEntityBranding($entities_id)) {
    http_response_code(404);
    return;
}

$name = Branding::getEffective($entities_id)['values'][$field] ?? '';
$path = Branding::getFilePath($name);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    return;
}

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = array_search($ext, BrandingResolver::ALLOWED_MIMES, true) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
// SVG can embed scripts: forbid them when the image is opened directly.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
header('Cache-Control: ' . ($entities_id === 0 ? 'public' : 'private') . ', max-age=31536000, immutable');
readfile($path);
