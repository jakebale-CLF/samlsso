<?php

/**
 * ------------------------------------------------------------------------
 * customBranding plugin for GLPI
 * ------------------------------------------------------------------------
 *
 * Plugin "configure" link: branding is set per entity, open the root entity tab.
 */

use GlpiPlugin\Custombranding\Branding;

Session::checkRight('entity', UPDATE);

Html::redirect(Entity::getFormURLWithID(0) . '&forcetab=' . urlencode(Branding::class . '$1'));
