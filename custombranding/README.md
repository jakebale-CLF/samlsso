# Custom Branding for GLPI

White-label GLPI 11 per entity, in the spirit of the GLPI Network *Branding* plugin.

## Features

| What | Where it shows |
|---|---|
| Main logo (expanded menu) | Side/top menu |
| Reduced logo (collapsed menu) | Side menu when collapsed |
| Login page logo | Login, lost password and other anonymous pages |
| Favicon | Browser tab |
| Login page background image and colour | Anonymous pages |
| Primary colour | Buttons, links, active elements |
| Main menu background and text colours | Menu (text colour is picked automatically if left empty) |
| Application name | Browser tab title (`Tickets - GLPI` becomes `Tickets - Acme Service Desk`) |
| Custom CSS | Every page |

* **Per entity, with inheritance.** Each entity gets a **Branding** tab. Any empty field inherits from the parent entity, up to the root. The root entity's branding is also what the login page shows.
* Works with every GLPI colour palette, light and dark.
* Uploads are checked by their content (not their extension), limited to 2 MB, and stored in `files/_plugins/custombranding/`. SVGs are served with a Content Security Policy that blocks scripts.
* Branding for an entity is only served to users who have access to that entity. The root entity's branding is public, because the login page needs it.

## Requirements

* GLPI 11.0.x
* PHP 8.2+ with `fileinfo`

## Install

```sh
cp -r custombranding /var/www/glpi/plugins/custombranding
```

Then go to **Setup > Plugins**, click **Install**, then **Enable**.

## Use

1. Go to **Administration > Entities** and open an entity (for example *Root entity*). You can also click the plugin's *configure* icon, which opens the root entity.
2. Open the **Branding** tab, fill in what you want to change and click **Save**.
3. Reload the page. Assets are cache-busted automatically whenever the branding changes.

To go back to the inherited or default value, clear a colour or text field, or tick **Remove** under an image.

## Tests

```sh
php tests/BrandingResolverTest.php
```

## How it works

* `src/BrandingResolver.php` has the pure logic: inheritance, colour validation, CSS sanitising and stylesheet generation.
* `src/Branding.php` connects it to GLPI: the entity tab, storage, install/uninstall, and the `add_header_tag` and `add_header_tag_anonymous_page` hooks that inject the generated stylesheet.
* `front/style.php` and `front/resource.php` serve the stylesheet and images. They are declared public in `plugin_custombranding_boot()` and do their own access checks.
* `public/js/branding.js` sets the tab title and favicon, which CSS cannot change.

## Licence

GPLv3+
