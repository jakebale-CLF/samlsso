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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Custombranding\Branding;

Session::checkRight('entity', UPDATE);

if (!isset($_POST['save'], $_POST['entities_id'])) {
    throw new BadRequestHttpException();
}

$entities_id = (int) $_POST['entities_id'];
$entity      = new Entity();
if (!$entity->getFromDB($entities_id) || !$entity->can($entities_id, UPDATE)) {
    throw new AccessDeniedHttpException();
}

$errors = Branding::saveForEntity($entities_id, $_POST, $_FILES);
foreach ($errors as $error) {
    Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
}
if ($errors === []) {
    Session::addMessageAfterRedirect(__('Branding saved.', 'custombranding'));
}

Html::back();
