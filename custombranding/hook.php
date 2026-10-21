<?php

/**
 * ------------------------------------------------------------------------
 * customBranding plugin for GLPI
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

use GlpiPlugin\Custombranding\Branding;

function plugin_custombranding_install(): bool
{
    $migration = new Migration(PLUGIN_CUSTOMBRANDING_VERSION);
    Branding::install($migration);
    $migration->executeMigration();
    return true;
}

function plugin_custombranding_uninstall(): bool
{
    $migration = new Migration(PLUGIN_CUSTOMBRANDING_VERSION);
    Branding::uninstall($migration);
    $migration->executeMigration();
    return true;
}
