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

namespace GlpiPlugin\Custombranding;

use CommonDBTM;
use CommonGLPI;
use DBConnection;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use Migration;
use Session;

/**
 * One branding row per entity. Empty fields inherit from the parent entity.
 */
class Branding extends CommonDBTM
{
    public static $rightname = 'entity';

    /** Per-request cache of resolved branding, keyed by entity id. */
    private static array $resolvedCache = [];

    public static function getTypeName($nb = 0)
    {
        return __('Branding', 'custombranding');
    }

    public static function getIcon()
    {
        return 'ti ti-palette';
    }

    /**
     * Directory holding uploaded images.
     */
    public static function getStorageDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/custombranding';
    }

    /**
     * Public base path of the plugin, e.g. "/glpi/plugins/custombranding".
     */
    public static function getWebDir(): string
    {
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/custombranding';
    }

    // ---------------------------------------------------------------------
    // Entity tab
    // ---------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Entity && Session::haveRight(self::$rightname, READ)) {
            return self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Entity) {
            self::showForEntity($item);
        }
        return true;
    }

    /**
     * Render the branding form for an entity.
     */
    public static function showForEntity(Entity $entity): void
    {
        $entities_id = (int) $entity->getID();
        $branding    = new self();
        $own         = $branding->getFromDBByCrit(['entities_id' => $entities_id]) ? $branding->fields : [];

        // What this entity would get if its own values were all empty.
        $parent = [];
        if ($entities_id > 0) {
            $parentChain = array_slice(self::getEntityChain($entities_id), 1);
            $parent = BrandingResolver::resolve(self::loadRows($parentChain), $parentChain);
        }

        $previewUrls = [];
        foreach (array_keys(BrandingResolver::FILE_FIELDS) as $field) {
            if (!empty($own[$field])) {
                $previewUrls[$field] = self::getResourceUrl($entities_id, $field, (string) $own[$field]);
            }
        }

        TemplateRenderer::getInstance()->display('@custombranding/branding_form.html.twig', [
            'entities_id'   => $entities_id,
            'form_url'      => self::getFormURL(),
            'own'           => $own,
            'inherited'     => $parent['values'] ?? [],
            'is_root'       => $entities_id === 0,
            'can_edit'      => $entity->can($entities_id, UPDATE),
            'file_fields'   => self::translatedLabels(BrandingResolver::FILE_FIELDS),
            'color_fields'  => self::translatedLabels(BrandingResolver::COLOR_FIELDS),
            'preview_urls'  => $previewUrls,
            'accept'        => implode(',', array_keys(BrandingResolver::ALLOWED_MIMES)),
            'max_size_mb'   => BrandingResolver::MAX_UPLOAD_SIZE / 1024 / 1024,
        ]);
    }

    /**
     * @param array<string, string> $labels
     * @return array<string, string>
     */
    private static function translatedLabels(array $labels): array
    {
        return array_map(static fn(string $label): string => __($label, 'custombranding'), $labels);
    }

    // ---------------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------------

    /**
     * Persist the form for an entity, including file uploads and deletions.
     *
     * @param array $input  Sanitised POST data.
     * @param array $files  $_FILES.
     * @return string[] Error messages (empty on full success).
     */
    public static function saveForEntity(int $entities_id, array $input, array $files): array
    {
        $errors   = [];
        $branding = new self();
        $exists   = $branding->getFromDBByCrit(['entities_id' => $entities_id]);
        $current  = $exists ? $branding->fields : [];
        $data     = ['entities_id' => $entities_id];

        $data['page_title'] = trim((string) ($input['page_title'] ?? ''));
        $data['custom_css'] = BrandingResolver::sanitizeCss((string) ($input['custom_css'] ?? ''));

        foreach (array_keys(BrandingResolver::COLOR_FIELDS) as $field) {
            $raw = trim((string) ($input[$field] ?? ''));
            $color = BrandingResolver::normalizeColor($raw);
            if ($raw !== '' && $color === null) {
                $errors[] = sprintf(__('Invalid colour for "%s": %s', 'custombranding'), $field, $raw);
            }
            $data[$field] = $color ?? '';
        }

        foreach (array_keys(BrandingResolver::FILE_FIELDS) as $field) {
            $data[$field] = (string) ($current[$field] ?? '');

            if (!empty($input['_delete_' . $field]) && $data[$field] !== '') {
                self::deleteFile($data[$field]);
                $data[$field] = '';
            }

            $upload = $files[$field] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $stored = self::storeUpload($entities_id, $field, $upload, $error);
            if ($stored === null) {
                $errors[] = sprintf('%s: %s', __(BrandingResolver::FILE_FIELDS[$field], 'custombranding'), $error);
                continue;
            }
            if ($data[$field] !== '' && $data[$field] !== $stored) {
                self::deleteFile($data[$field]);
            }
            $data[$field] = $stored;
        }

        if ($exists) {
            $data['id'] = $branding->getID();
            $branding->update($data);
        } else {
            $branding->add($data);
        }
        self::$resolvedCache = [];

        return $errors;
    }

    /**
     * Validate and move an uploaded image into plugin storage.
     *
     * @return string|null Stored file name, or null on error (see $error).
     */
    private static function storeUpload(int $entities_id, string $field, array $upload, ?string &$error): ?string
    {
        $error = null;
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            $error = __('Upload failed.', 'custombranding');
            return null;
        }
        if ((int) $upload['size'] > BrandingResolver::MAX_UPLOAD_SIZE) {
            $error = sprintf(__('File is larger than %d MB.', 'custombranding'), BrandingResolver::MAX_UPLOAD_SIZE / 1024 / 1024);
            return null;
        }

        // Never trust the browser supplied type: sniff the content.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if ($mime === 'text/xml' || $mime === 'application/xml' || $mime === 'text/plain') {
            // finfo reports SVG without an XML prolog in various ways.
            $head = (string) file_get_contents($upload['tmp_name'], false, null, 0, 1024);
            if (stripos($head, '<svg') !== false) {
                $mime = 'image/svg+xml';
            }
        }
        $ext = BrandingResolver::extensionForMime($mime);
        if ($ext === null) {
            $error = sprintf(__('Unsupported file type %s.', 'custombranding'), $mime);
            return null;
        }

        $dir = self::getStorageDir();
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            $error = __('Cannot create storage directory.', 'custombranding');
            return null;
        }

        $name = sprintf('%d_%s_%s.%s', $entities_id, $field, substr(sha1_file($upload['tmp_name']), 0, 16), $ext);
        if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $name)) {
            $error = __('Cannot store uploaded file.', 'custombranding');
            return null;
        }
        return $name;
    }

    private static function deleteFile(string $name): void
    {
        $path = self::getFilePath($name);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Absolute path of a stored file, refusing anything that is not a plain file name.
     */
    public static function getFilePath(string $name): ?string
    {
        if ($name === '' || basename($name) !== $name || !preg_match('/^[\w.-]+$/', $name)) {
            return null;
        }
        return self::getStorageDir() . '/' . $name;
    }

    public function cleanDBonPurge()
    {
        foreach (array_keys(BrandingResolver::FILE_FIELDS) as $field) {
            if (!empty($this->fields[$field])) {
                self::deleteFile((string) $this->fields[$field]);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Resolution
    // ---------------------------------------------------------------------

    /**
     * Entity ids from the given entity up to the root, closest first.
     *
     * @return int[]
     */
    public static function getEntityChain(int $entities_id): array
    {
        global $DB;

        if ($entities_id <= 0) {
            return [0];
        }
        $ancestors = array_diff(array_map('intval', array_values(getAncestorsOf(Entity::getTable(), $entities_id))), [$entities_id]);
        if ($ancestors === []) {
            return [$entities_id];
        }

        // getAncestorsOf() does not guarantee an order: sort deepest (closest) first.
        $chain = [$entities_id];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Entity::getTable(),
                'WHERE'  => ['id' => array_values($ancestors)],
                'ORDER'  => ['level DESC'],
            ]) as $row
        ) {
            $chain[] = (int) $row['id'];
        }
        return $chain;
    }

    /**
     * @param int[] $entities_ids
     * @return array<int, array<string, mixed>>
     */
    private static function loadRows(array $entities_ids): array
    {
        global $DB;

        if ($entities_ids === []) {
            return [];
        }
        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['entities_id' => $entities_ids]]) as $row) {
            $rows[(int) $row['entities_id']] = $row;
        }
        return $rows;
    }

    /**
     * Effective branding for an entity, with inheritance applied.
     *
     * @return array{values: array<string, string>, sources: array<string, int>, version: string}
     */
    public static function getEffective(int $entities_id): array
    {
        if (!isset(self::$resolvedCache[$entities_id])) {
            $chain    = self::getEntityChain($entities_id);
            $resolved = BrandingResolver::resolve(self::loadRows($chain), $chain);
            $resolved['version'] = BrandingResolver::fingerprint($resolved);
            self::$resolvedCache[$entities_id] = $resolved;
        }
        return self::$resolvedCache[$entities_id];
    }

    /**
     * Public URL of an image as seen by the given entity.
     */
    public static function getResourceUrl(int $entities_id, string $field, string $version): string
    {
        return self::getWebDir() . '/front/resource.php?' . http_build_query([
            'e' => $entities_id,
            'f' => $field,
            'v' => substr(sha1($version), 0, 12),
        ]);
    }

    /**
     * Image URLs for each image field defined in the effective branding.
     *
     * @return array<string, string>
     */
    public static function getEffectiveUrls(int $entities_id): array
    {
        $effective = self::getEffective($entities_id);
        $urls = [];
        foreach (array_keys(BrandingResolver::FILE_FIELDS) as $field) {
            if (isset($effective['values'][$field])) {
                $urls[$field] = self::getResourceUrl($entities_id, $field, $effective['values'][$field]);
            }
        }
        return $urls;
    }

    /**
     * Entity whose branding applies to the current request.
     *
     * Anonymous pages (login, lost password...) use the root entity.
     */
    public static function getCurrentEntityId(): int
    {
        if (Session::getLoginUserID() !== false && isset($_SESSION['glpiactive_entity'])) {
            return (int) $_SESSION['glpiactive_entity'];
        }
        return 0;
    }

    /**
     * Whether the current requester may read the branding of an entity.
     */
    public static function canViewEntityBranding(int $entities_id): bool
    {
        if ($entities_id === 0) {
            // Root branding is shown on the login page, it is public by design.
            return true;
        }
        return Session::getLoginUserID() !== false && Session::haveAccessToEntity($entities_id);
    }

    /**
     * Header tags injected into every page (stylesheet, favicon, title metadata).
     *
     * @return array<int, array{tag: string, properties: array<string, string>}>
     */
    public static function getHeaderTags(int $entities_id): array
    {
        global $CFG_GLPI;

        $effective = self::getEffective($entities_id);
        if ($effective['values'] === []) {
            return [];
        }

        $tags = [[
            'tag'        => 'link',
            'properties' => [
                'rel'  => 'stylesheet',
                'type' => 'text/css',
                'href' => self::getWebDir() . '/front/style.php?' . http_build_query([
                    'e' => $entities_id,
                    'v' => $effective['version'],
                ]),
            ],
        ]];

        $urls = self::getEffectiveUrls($entities_id);
        if (isset($urls['favicon'])) {
            $tags[] = ['tag' => 'link', 'properties' => ['rel' => 'icon', 'href' => $urls['favicon']]];
        }

        // Read by public/js/branding.js to update the title and favicon at runtime.
        $tags[] = [
            'tag'        => 'meta',
            'properties' => [
                'name'          => 'custombranding',
                'content'       => '1',
                'data-title'    => $effective['values']['page_title'] ?? '',
                'data-app-name' => (string) ($CFG_GLPI['app_name'] ?? 'GLPI'),
                'data-favicon'  => $urls['favicon'] ?? '',
            ],
        ];

        return $tags;
    }

    // ---------------------------------------------------------------------
    // Install / uninstall
    // ---------------------------------------------------------------------

    public static function install(Migration $migration): void
    {
        global $DB;

        $table             = self::getTable();
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        if (!$DB->tableExists($table)) {
            $query = <<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id`               int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `entities_id`      int {$default_key_sign} NOT NULL DEFAULT '0',
                `page_title`       varchar(255) NOT NULL DEFAULT '',
                `logo_large`       varchar(255) NOT NULL DEFAULT '',
                `logo_small`       varchar(255) NOT NULL DEFAULT '',
                `logo_login`       varchar(255) NOT NULL DEFAULT '',
                `favicon`          varchar(255) NOT NULL DEFAULT '',
                `login_background` varchar(255) NOT NULL DEFAULT '',
                `primary_color`    varchar(7)   NOT NULL DEFAULT '',
                `menu_bg_color`    varchar(7)   NOT NULL DEFAULT '',
                `menu_fg_color`    varchar(7)   NOT NULL DEFAULT '',
                `login_bg_color`   varchar(7)   NOT NULL DEFAULT '',
                `custom_css`       mediumtext,
                `date_creation`    timestamp NULL DEFAULT NULL,
                `date_mod`         timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
            SQL;
            $DB->doQuery($query);
        }

        $dir = self::getStorageDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::getTable());

        $dir = self::getStorageDir();
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
