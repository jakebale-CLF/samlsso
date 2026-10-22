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

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Custombranding\Branding;

define('PLUGIN_CUSTOMBRANDING_VERSION', '1.0.0');
define('PLUGIN_CUSTOMBRANDING_MIN_GLPI', '11.0.0');
define('PLUGIN_CUSTOMBRANDING_MAX_GLPI', '12.99.99');

/**
 * Runs before the session is started: declare the public endpoints.
 */
function plugin_custombranding_boot(): void
{
    // The stylesheet and images must load on the login page, before authentication.
    // Both scripts perform their own entity access checks.
    Firewall::addPluginStrategyForLegacyScripts('custombranding', '#^/front/style\.php$#', Firewall::STRATEGY_NO_CHECK);
    Firewall::addPluginStrategyForLegacyScripts('custombranding', '#^/front/resource\.php$#', Firewall::STRATEGY_NO_CHECK);
}

function plugin_init_custombranding(): void
{
    global $PLUGIN_HOOKS;

    if (!Plugin::isPluginActive('custombranding')) {
        return;
    }

    Plugin::registerClass(Branding::class, ['addtabon' => [Entity::class]]);

    if (Session::haveRight(Branding::RIGHTNAME, UPDATE)) {
        $PLUGIN_HOOKS['config_page']['custombranding'] = 'front/config.php';
    }

    // Title / favicon runtime adjustments, on both logged in and anonymous pages.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['custombranding']                = ['js/branding.js'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_ANONYMOUS_PAGE]['custombranding'] = ['js/branding.js'];

    // Stylesheet, favicon and metadata for the entity in use.
    try {
        $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG]['custombranding']
            = Branding::getHeaderTags(Branding::getCurrentEntityId());
        $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG_ANONYMOUS_PAGE]['custombranding']
            = Branding::getHeaderTags(0);
    } catch (Throwable $e) {
        // Never break GLPI because of branding (e.g. table missing during an upgrade).
        Toolbox::logInFile('custombranding', $e->getMessage() . "\n");
    }
}

function plugin_version_custombranding(): array
{
    return [
        'name'         => 'Custom Branding',
        'version'      => PLUGIN_CUSTOMBRANDING_VERSION,
        'author'       => 'CLF',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CUSTOMBRANDING_MIN_GLPI,
                'max' => PLUGIN_CUSTOMBRANDING_MAX_GLPI,
            ],
            'php' => [
                'min'  => '8.2',
                'exts' => ['fileinfo' => ['required' => true]],
            ],
        ],
    ];
}

function plugin_custombranding_check_prerequisites(): bool
{
    return true;
}

function plugin_custombranding_check_config($verbose = false): bool
{
    return true;
}
