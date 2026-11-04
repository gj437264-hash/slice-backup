<?php
/**
 * Admin screen template.
 *
 * @package WpBundleMigrator
 *
 * @var array<string, mixed> $view Data from Plugin::viewData().
 */

if (!defined('ABSPATH')) {
    exit;
}

$wpbm_components = $view['components'];
$wpbm_presets    = array(
    'full'         => array(
        'title' => __('Full site', 'wp-bundle-migrator'),
        'desc'  => __('Database, media, plugins, themes and config. Best for moving a site.', 'wp-bundle-migrator'),
        'icon'  => 'dashicons-admin-site-alt3',
    ),
    'turnkey_full' => array(
        'title' => __('Full site + WordPress', 'wp-bundle-migrator'),
        'desc'  => __('Everything above plus WordPress core files.', 'wp-bundle-migrator'),
        'icon'  => 'dashicons-wordpress-alt',
    ),
    'db_only'      => array(
        'title' => __('Database only', 'wp-bundle-migrator'),
        'desc'  => __('Content and settings, no files. Quick safety backup.', 'wp-bundle-migrator'),
        'icon'  => 'dashicons-database',
    ),
    'media_only'   => array(
        'title' => __('Media only', 'wp-bundle-migrator'),
        'desc'  => __('Just the uploads folder.', 'wp-bundle-migrator'),
        'icon'  => 'dashicons-format-gallery',
    ),
    'custom'       => array(
        'title' => __('Custom', 'wp-bundle-migrator'),
        'desc'  => __('Pick exactly what to include.', 'wp-bundle-migrator'),
        'icon'  => 'dashicons-admin-settings',
    ),
);
$wpbm_tabs = array(
    'export'   => array(__('Back up', 'wp-bundle-migrator'), 'dashicons-upload'),
    'import'   => array(__('Restore', 'wp-bundle-migrator'), 'dashicons-download'),
    'backups'  => array(__('Backups', 'wp-bundle-migrator'), 'dashicons-portfolio'),
    'settings' => array(__('Settings', 'wp-bundle-migrator'), 'dashicons-admin-generic'),
    'system'   => array(__('System', 'wp-bundle-migrator'), 'dashicons-heart'),
);
$wpbm_status_labels = array(
    'ok'   => __('OK', 'wp-bundle-migrator'),
    'warn' => __('Check', 'wp-bundle-migrator'),
    'fail' => __('Problem', 'wp-bundle-migrator'),
    'info' => __('Info', 'wp-bundle-migrator'),
);
$wpbm_problems = array_filter(
    $view['checks'],
    static function ($row) {
        return $row['status'] === 'fail';
    }
);
?>
<div class="wrap wpbm" id="wpbm-app">
    <header class="wpbm-header">
        <div class="wpbm-header__title">
            <span class="wpbm-header__logo dashicons dashicons-migrate" aria-hidden="true"></span>
            <div>
                <h1><?php esc_html_e('WP Bundle Migrator', 'wp-bundle-migrator'); ?></h1>
                <p class="wpbm-header__sub"><?php esc_html_e('Back up, move and restore this site as a single file.', 'wp-bundle-migrator'); ?></p>
            </div>
        </div>
        <ul class="wpbm-header__meta">
            <li><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php echo esc_html((string) wp_parse_url($view['siteUrl'], PHP_URL_HOST)); ?></li>
            <li><?php echo esc_html('PHP ' . PHP_VERSION); ?></li>
            <li><?php echo esc_html('v' . WPBM_VERSION); ?></li>
        </ul>
    </header>
    <hr class="wp-header-end">

    <?php if (!empty($wpbm_problems)) : ?>
        <div class="wpbm-callout wpbm-callout--error" role="alert">
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <div>
                <strong><?php esc_html_e('This server needs attention before backups will work.', 'wp-bundle-migrator'); ?></strong>
                <p><?php esc_html_e('Open the System tab for details.', 'wp-bundle-migrator'); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <nav class="wpbm-tabs" role="tablist" aria-label="<?php esc_attr_e('Bundle Migrator sections', 'wp-bundle-migrator'); ?>">
        <?php foreach ($wpbm_tabs as $wpbm_id => $wpbm_tab) : ?>
            <button type="button" class="wpbm-tab" role="tab" id="wpbm-tab-<?php echo esc_attr($wpbm_id); ?>" aria-controls="wpbm-panel-<?php echo esc_attr($wpbm_id); ?>" aria-selected="false" tabindex="-1" data-tab="<?php echo esc_attr($wpbm_id); ?>">
                <span class="dashicons <?php echo esc_attr($wpbm_tab[1]); ?>" aria-hidden="true"></span>
                <?php echo esc_html($wpbm_tab[0]); ?>
            </button>
        <?php endforeach; ?>
    </nav>

    <!-- JOB PROGRESS (shared by backup, restore, upload and download) -->
    <div class="wpbm-card wpbm-job" id="wpbm-job" hidden aria-live="polite">
        <div class="wpbm-job__head">
            <span class="spinner is-active" aria-hidden="true"></span>
            <div class="wpbm-job__titles">
                <strong id="wpbm-job-title"></strong>
                <span class="wpbm-muted" id="wpbm-job-label"></span>
            </div>
            <span class="wpbm-job__percent" id="wpbm-job-percent">0%</span>
        </div>
        <div class="wpbm-progress__bar" role="progressbar" aria-labelledby="wpbm-job-title" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="wpbm-job-bar"><span style="width:0%"></span></div>
        <div class="wpbm-job__foot">
            <span class="wpbm-muted" id="wpbm-job-detail"></span>
            <span class="wpbm-muted"><span id="wpbm-job-elapsed">0:00</span> · <?php esc_html_e('Runs in the background; you can leave this page.', 'wp-bundle-migrator'); ?></span>
            <button type="button" class="button-link wpbm-link-danger" id="wpbm-job-cancel"><?php esc_html_e('Cancel', 'wp-bundle-migrator'); ?></button>
        </div>
    </div>

    <!-- BACK UP -->
    <section class="wpbm-panel" id="wpbm-panel-export" role="tabpanel" aria-labelledby="wpbm-tab-export" hidden>
        <form id="wpbm-export-form" class="wpbm-layout" novalidate>
            <div class="wpbm-layout__main">
                <?php if ($view['isMultisite']) : ?>
                    <div class="wpbm-card">
                        <h2 class="wpbm-card__title"><?php esc_html_e('Which part of the network?', 'wp-bundle-migrator'); ?></h2>
                        <div class="wpbm-segmented" role="radiogroup">
                            <label><input type="radio" name="multisite_mode" value="entire_network" checked> <span><?php esc_html_e('Entire network', 'wp-bundle-migrator'); ?></span></label>
                            <label><input type="radio" name="multisite_mode" value="subsite"> <span><?php esc_html_e('One site', 'wp-bundle-migrator'); ?></span></label>
                        </div>
                        <div id="wpbm-subsite-options" class="wpbm-stack" hidden>
                            <label class="wpbm-field">
                                <span class="wpbm-field__label"><?php esc_html_e('Site', 'wp-bundle-migrator'); ?></span>
                                <select name="subsite_id" id="wpbm-subsite">
                                    <?php foreach ($view['subsites'] as $wpbm_site) : ?>
                                        <option value="<?php echo esc_attr((string) $wpbm_site['id']); ?>"><?php echo esc_html(sprintf('%s — %s', $wpbm_site['name'] !== '' ? $wpbm_site['name'] : '#' . $wpbm_site['id'], $wpbm_site['url'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="wpbm-check">
                                <input type="checkbox" name="convert_subsite_standalone" value="1" checked>
                                <span>
                                    <strong><?php esc_html_e('Convert to a standalone site', 'wp-bundle-migrator'); ?></strong>
                                    <small><?php esc_html_e('Rename its tables so the bundle can be restored on a normal, single WordPress site.', 'wp-bundle-migrator'); ?></small>
                                </span>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="wpbm-card">
                    <h2 class="wpbm-card__title"><?php esc_html_e('What do you want to back up?', 'wp-bundle-migrator'); ?></h2>
                    <div class="wpbm-presets" role="radiogroup" aria-label="<?php esc_attr_e('Backup type', 'wp-bundle-migrator'); ?>">
                        <?php foreach ($wpbm_presets as $wpbm_key => $wpbm_preset) : ?>
                            <label class="wpbm-preset">
                                <input type="radio" name="export_type" value="<?php echo esc_attr($wpbm_key); ?>" <?php checked($wpbm_key, 'full'); ?>>
                                <span class="wpbm-preset__body">
                                    <span class="dashicons <?php echo esc_attr($wpbm_preset['icon']); ?>" aria-hidden="true"></span>
                                    <strong><?php echo esc_html($wpbm_preset['title']); ?></strong>
                                    <small><?php echo esc_html($wpbm_preset['desc']); ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <details class="wpbm-details" id="wpbm-export-contents">
                        <summary><?php esc_html_e('Contents', 'wp-bundle-migrator'); ?> <span class="wpbm-muted" id="wpbm-contents-count"></span></summary>
                        <div class="wpbm-components">
                            <?php foreach ($wpbm_components as $wpbm_key => $wpbm_component) : ?>
                                <label class="wpbm-component">
                                    <input type="checkbox" name="include_<?php echo esc_attr($wpbm_key); ?>" value="1" data-component="<?php echo esc_attr($wpbm_key); ?>">
                                    <span class="dashicons <?php echo esc_attr($wpbm_component['icon']); ?>" aria-hidden="true"></span>
                                    <span class="wpbm-component__text">
                                        <strong><?php echo esc_html($wpbm_component['label']); ?></strong>
                                        <small><?php echo esc_html($wpbm_component['description']); ?></small>
                                    </span>
                                    <span class="wpbm-component__size" data-size-for="<?php echo esc_attr($wpbm_key); ?>" aria-label="<?php esc_attr_e('Estimated size', 'wp-bundle-migrator'); ?>">…</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>

                    <details class="wpbm-details">
                        <summary><?php esc_html_e('Make the backup smaller', 'wp-bundle-migrator'); ?></summary>
                        <div class="wpbm-stack">
                            <label class="wpbm-check"><input type="checkbox" name="exclude_spam" value="1" checked> <span><strong><?php esc_html_e('Skip spam and trashed comments', 'wp-bundle-migrator'); ?></strong></span></label>
                            <label class="wpbm-check"><input type="checkbox" name="exclude_revisions" value="1"> <span><strong><?php esc_html_e('Skip post revisions and trashed posts', 'wp-bundle-migrator'); ?></strong></span></label>
                            <label class="wpbm-check"><input type="checkbox" name="exclude_transients" value="1" checked> <span><strong><?php esc_html_e('Skip cached data (transients)', 'wp-bundle-migrator'); ?></strong><small><?php esc_html_e('Safe: WordPress rebuilds this cache automatically.', 'wp-bundle-migrator'); ?></small></span></label>
                        </div>
                    </details>
                </div>

                <div class="wpbm-card">
                    <h2 class="wpbm-card__title"><?php esc_html_e('Protect and store', 'wp-bundle-migrator'); ?></h2>
                    <div class="wpbm-stack">
                        <label class="wpbm-check wpbm-check--boxed">
                            <input type="checkbox" name="encrypt" value="1" id="wpbm-export-encrypt" <?php checked(!empty($view['settings']['encryption']['by_default'])); ?> <?php disabled(!$view['cryptoReady']); ?>>
                            <span>
                                <strong><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e('Encrypt with AES-256', 'wp-bundle-migrator'); ?></strong>
                                <small><?php echo $view['cryptoReady'] ? esc_html__('Without the passphrase nobody can open the bundle, including the database password inside it.', 'wp-bundle-migrator') : esc_html__('Unavailable: this server lacks the PHP OpenSSL extension.', 'wp-bundle-migrator'); ?></small>
                            </span>
                        </label>
                        <div class="wpbm-indent" id="wpbm-export-passphrase-row" hidden>
                            <?php if (!empty($view['settings']['encryption']['has_passphrase'])) : ?>
                                <p class="wpbm-muted wpbm-small"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e('Uses your saved passphrase. Enter a different one below to override it for this backup.', 'wp-bundle-migrator'); ?></p>
                            <?php endif; ?>
                            <label class="wpbm-field">
                                <span class="wpbm-field__label"><?php esc_html_e('Passphrase', 'wp-bundle-migrator'); ?></span>
                                <input type="password" name="passphrase" autocomplete="new-password" minlength="8" placeholder="<?php echo esc_attr(!empty($view['settings']['encryption']['has_passphrase']) ? __('Saved passphrase', 'wp-bundle-migrator') : __('At least 8 characters', 'wp-bundle-migrator')); ?>">
                            </label>
                        </div>

                        <label class="wpbm-check wpbm-check--boxed">
                            <input type="checkbox" name="upload" value="1" id="wpbm-export-upload" <?php checked($view['cloudReady'] && !empty($view['settings']['cloud']['auto_upload'])); ?> <?php disabled(!$view['cloudReady']); ?>>
                            <span>
                                <strong><span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span> <?php esc_html_e('Upload to cloud storage', 'wp-bundle-migrator'); ?></strong>
                                <small>
                                    <?php
                                    if ($view['cloudReady']) {
                                        echo esc_html(sprintf(
                                            /* translators: %s: destination, e.g. "Wasabi · my-bucket". */
                                            __('Sends a copy to %s. Large bundles are uploaded in parts and resume after interruptions.', 'wp-bundle-migrator'),
                                            $view['cloudLabel']
                                        ));
                                    } else {
                                        esc_html_e('Not set up yet.', 'wp-bundle-migrator');
                                        echo ' <a href="#settings" data-go-tab="settings">' . esc_html__('Set up cloud storage', 'wp-bundle-migrator') . '</a>';
                                    }
                                    ?>
                                </small>
                            </span>
                        </label>
                        <label class="wpbm-check wpbm-indent" id="wpbm-export-delete-row" hidden>
                            <input type="checkbox" name="delete_local" value="1" <?php checked(!empty($view['settings']['cloud']['delete_local'])); ?>>
                            <span><strong><?php esc_html_e('Delete the server copy after a successful upload', 'wp-bundle-migrator'); ?></strong></span>
                        </label>

                        <label class="wpbm-check wpbm-check--boxed">
                            <input type="checkbox" name="push" value="1" id="wpbm-export-push">
                            <span>
                                <strong><span class="dashicons dashicons-randomize" aria-hidden="true"></span> <?php esc_html_e('Send directly to another site', 'wp-bundle-migrator'); ?></strong>
                                <small><?php esc_html_e('Moves the site without downloading anything. On the other site, install this plugin and create a transfer key under Settings > Site-to-site transfer.', 'wp-bundle-migrator'); ?></small>
                            </span>
                        </label>
                        <div class="wpbm-indent wpbm-stack" id="wpbm-export-push-row" hidden>
                            <label class="wpbm-field">
                                <span class="wpbm-field__label"><?php esc_html_e('Transfer key of the other site', 'wp-bundle-migrator'); ?></span>
                                <textarea name="push_key" rows="3" class="wpbm-key-input" spellcheck="false" autocomplete="off" placeholder="wpbm1.…"></textarea>
                            </label>
                            <div class="wpbm-inline">
                                <button type="button" class="button" id="wpbm-push-check"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('Check key', 'wp-bundle-migrator'); ?></button>
                                <span class="wpbm-test-result" id="wpbm-push-check-result" role="status"></span>
                            </div>
                            <label class="wpbm-check">
                                <input type="checkbox" name="push_restore" value="1" checked>
                                <span>
                                    <strong><?php esc_html_e('Restore it on the other site when it arrives', 'wp-bundle-migrator'); ?></strong>
                                    <small><?php esc_html_e('Overwrites the other site with this one and updates its addresses. Server config files and WordPress core are never applied automatically.', 'wp-bundle-migrator'); ?></small>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="wpbm-layout__side">
                <div class="wpbm-card wpbm-summary" aria-live="polite">
                    <h2 class="wpbm-card__title"><?php esc_html_e('Summary', 'wp-bundle-migrator'); ?></h2>
                    <ul class="wpbm-summary__list" id="wpbm-export-summary"></ul>
                    <p class="wpbm-summary__size">
                        <span><?php esc_html_e('Estimated size (before compression)', 'wp-bundle-migrator'); ?></span>
                        <strong id="wpbm-export-estimate">…</strong>
                    </p>
                    <div class="wpbm-callout wpbm-callout--warning" id="wpbm-config-warning" hidden>
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <p><?php esc_html_e('Includes wp-config.php, which contains your database password. Keep this bundle private.', 'wp-bundle-migrator'); ?></p>
                    </div>
                    <p class="wpbm-error-text" id="wpbm-export-empty" hidden><?php esc_html_e('Select at least one item.', 'wp-bundle-migrator'); ?></p>
                    <button type="submit" class="button button-primary button-hero wpbm-block" id="wpbm-export-start">
                        <span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e('Create backup', 'wp-bundle-migrator'); ?>
                    </button>
                </div>

                <div class="wpbm-card wpbm-result" id="wpbm-export-result" hidden tabindex="-1">
                    <span class="wpbm-result__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <h2 class="wpbm-card__title"><?php esc_html_e('Backup ready', 'wp-bundle-migrator'); ?></h2>
                    <p class="wpbm-result__file" id="wpbm-export-result-name"></p>
                    <p class="wpbm-muted" id="wpbm-export-result-size"></p>
                    <p class="wpbm-chips wpbm-chips--center" id="wpbm-export-result-badges"></p>
                    <a class="button button-primary wpbm-block" id="wpbm-export-download" href="#"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Download', 'wp-bundle-migrator'); ?></a>
                    <p class="wpbm-muted wpbm-small"><?php esc_html_e('To move the site: install WordPress and this plugin on the new server, then upload this file under Restore.', 'wp-bundle-migrator'); ?></p>
                </div>
            </aside>
        </form>
    </section>

    <!-- RESTORE -->
    <section class="wpbm-panel" id="wpbm-panel-import" role="tabpanel" aria-labelledby="wpbm-tab-import" hidden>
        <div class="wpbm-layout wpbm-layout--narrow">
            <div class="wpbm-layout__main">
                <div class="wpbm-card">
                    <h2 class="wpbm-card__title"><span class="wpbm-step">1</span><?php esc_html_e('Choose a bundle', 'wp-bundle-migrator'); ?></h2>
                    <div class="wpbm-segmented" role="tablist" aria-label="<?php esc_attr_e('Bundle source', 'wp-bundle-migrator'); ?>">
                        <button type="button" class="is-active" data-source="upload" aria-pressed="true"><?php esc_html_e('Upload a file', 'wp-bundle-migrator'); ?></button>
                        <button type="button" data-source="saved" aria-pressed="false"><?php esc_html_e('Use a saved backup', 'wp-bundle-migrator'); ?></button>
                        <button type="button" data-source="cloud" aria-pressed="false"><?php esc_html_e('From cloud storage', 'wp-bundle-migrator'); ?></button>
                        <button type="button" data-source="peer" aria-pressed="false"><?php esc_html_e('From another site', 'wp-bundle-migrator'); ?></button>
                    </div>

                    <div id="wpbm-source-peer" hidden>
                        <p class="wpbm-muted"><?php esc_html_e('Copies another WordPress site to this one. On the other site, install this plugin, create a transfer key under Settings > Site-to-site transfer, and paste it here.', 'wp-bundle-migrator'); ?></p>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Transfer key of the other site', 'wp-bundle-migrator'); ?></span>
                            <textarea id="wpbm-pull-key" rows="3" class="wpbm-key-input" spellcheck="false" autocomplete="off" placeholder="wpbm1.…"></textarea>
                        </label>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('What to copy', 'wp-bundle-migrator'); ?></span>
                            <select id="wpbm-pull-preset">
                                <?php foreach ($wpbm_presets as $wpbm_key => $wpbm_preset) : ?>
                                    <?php if (!in_array($wpbm_key, array('custom', 'turnkey_full'), true)) : ?>
                                        <option value="<?php echo esc_attr($wpbm_key); ?>"><?php echo esc_html($wpbm_preset['title']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="wpbm-inline">
                            <button type="button" class="button button-primary" id="wpbm-pull-start"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Fetch from the other site', 'wp-bundle-migrator'); ?></button>
                            <span class="wpbm-test-result" id="wpbm-pull-result" role="status"></span>
                        </div>
                        <p class="wpbm-muted wpbm-small"><?php esc_html_e('The bundle is created on the other site and downloaded here. You then choose what to restore below.', 'wp-bundle-migrator'); ?></p>
                    </div>

                    <div id="wpbm-source-upload">
                        <label class="wpbm-dropzone" id="wpbm-dropzone" for="wpbm-file">
                            <span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
                            <strong><?php esc_html_e('Drop a bundle here or click to browse', 'wp-bundle-migrator'); ?></strong>
                            <small><?php esc_html_e('.zip files of any size; large files are uploaded in pieces.', 'wp-bundle-migrator'); ?></small>
                            <input type="file" id="wpbm-file" accept=".zip,.wpbundle,application/zip" class="screen-reader-text">
                        </label>
                        <div class="wpbm-progress" id="wpbm-upload-progress" hidden>
                            <div class="wpbm-progress__bar"><span id="wpbm-upload-bar" style="width:0%"></span></div>
                            <div class="wpbm-progress__row">
                                <p class="wpbm-progress__text" id="wpbm-upload-text" aria-live="polite"></p>
                                <button type="button" class="button-link wpbm-link-danger" id="wpbm-upload-cancel"><?php esc_html_e('Cancel', 'wp-bundle-migrator'); ?></button>
                            </div>
                        </div>
                    </div>

                    <div id="wpbm-source-saved" hidden>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Saved backup', 'wp-bundle-migrator'); ?></span>
                            <select id="wpbm-saved-select">
                                <option value=""><?php esc_html_e('Select a backup…', 'wp-bundle-migrator'); ?></option>
                            </select>
                        </label>
                    </div>

                    <div id="wpbm-source-cloud" hidden>
                        <?php if ($view['cloudReady']) : ?>
                            <label class="wpbm-field">
                                <span class="wpbm-field__label"><?php esc_html_e('Bundle in cloud storage', 'wp-bundle-migrator'); ?></span>
                                <select id="wpbm-cloud-select">
                                    <option value=""><?php esc_html_e('Loading…', 'wp-bundle-migrator'); ?></option>
                                </select>
                            </label>
                            <button type="button" class="button" id="wpbm-cloud-fetch" disabled><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Download to this server', 'wp-bundle-migrator'); ?></button>
                        <?php else : ?>
                            <p class="wpbm-muted"><?php esc_html_e('Cloud storage is not set up.', 'wp-bundle-migrator'); ?> <a href="#settings" data-go-tab="settings"><?php esc_html_e('Set it up', 'wp-bundle-migrator'); ?></a></p>
                        <?php endif; ?>
                    </div>

                    <div id="wpbm-unlock" class="wpbm-unlock" hidden>
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <div class="wpbm-unlock__body">
                            <strong><?php esc_html_e('This bundle is encrypted', 'wp-bundle-migrator'); ?></strong>
                            <p class="wpbm-muted wpbm-small" id="wpbm-unlock-error"><?php esc_html_e('Enter the passphrase that was used to create it.', 'wp-bundle-migrator'); ?></p>
                            <div class="wpbm-inline">
                                <label class="screen-reader-text" for="wpbm-unlock-pass"><?php esc_html_e('Passphrase', 'wp-bundle-migrator'); ?></label>
                                <input type="password" id="wpbm-unlock-pass" autocomplete="off" placeholder="<?php esc_attr_e('Passphrase', 'wp-bundle-migrator'); ?>">
                                <button type="button" class="button button-primary" id="wpbm-unlock-btn"><?php esc_html_e('Unlock', 'wp-bundle-migrator'); ?></button>
                            </div>
                        </div>
                    </div>

                    <div id="wpbm-bundle-details" class="wpbm-bundle-details" hidden></div>
                </div>

                <form id="wpbm-restore-form" novalidate hidden>
                    <div class="wpbm-card">
                        <h2 class="wpbm-card__title"><span class="wpbm-step">2</span><?php esc_html_e('Addresses', 'wp-bundle-migrator'); ?></h2>
                        <p class="wpbm-muted"><?php esc_html_e('Links in the database that point to the old address are rewritten to this site\'s address. Serialized data stays valid.', 'wp-bundle-migrator'); ?></p>
                        <div class="wpbm-url-row">
                            <label class="wpbm-field">
                                <span class="wpbm-field__label"><?php esc_html_e('Old address', 'wp-bundle-migrator'); ?></span>
                                <input type="url" name="old_url" id="wpbm-old-url" class="regular-text" placeholder="https://old-site.example">
                            </label>
                            <span class="dashicons dashicons-arrow-right-alt wpbm-url-row__arrow" aria-hidden="true"></span>
                            <label class="wpbm-field">
                                <span class="wpbm-field__label"><?php esc_html_e('New address', 'wp-bundle-migrator'); ?></span>
                                <input type="url" name="new_url" id="wpbm-new-url" class="regular-text" value="<?php echo esc_attr(untrailingslashit($view['siteUrl'])); ?>">
                            </label>
                        </div>
                        <?php if ($view['isMultisite']) : ?>
                            <div class="wpbm-url-row" id="wpbm-network-fields" hidden>
                                <label class="wpbm-field">
                                    <span class="wpbm-field__label"><?php esc_html_e('Old network domain', 'wp-bundle-migrator'); ?></span>
                                    <input type="text" name="old_network_domain" id="wpbm-old-domain" class="regular-text">
                                </label>
                                <span class="dashicons dashicons-arrow-right-alt wpbm-url-row__arrow" aria-hidden="true"></span>
                                <label class="wpbm-field">
                                    <span class="wpbm-field__label"><?php esc_html_e('New network domain', 'wp-bundle-migrator'); ?></span>
                                    <input type="text" name="new_network_domain" id="wpbm-new-domain" class="regular-text" value="<?php echo esc_attr(defined('DOMAIN_CURRENT_SITE') ? DOMAIN_CURRENT_SITE : ''); ?>">
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="wpbm-card">
                        <h2 class="wpbm-card__title"><span class="wpbm-step">3</span><?php esc_html_e('What to restore', 'wp-bundle-migrator'); ?></h2>
                        <div class="wpbm-components wpbm-components--compact">
                            <?php foreach ($wpbm_components as $wpbm_key => $wpbm_component) : ?>
                                <label class="wpbm-component">
                                    <input type="checkbox" name="restore_<?php echo esc_attr($wpbm_key); ?>" value="1" data-restore="<?php echo esc_attr($wpbm_key); ?>">
                                    <span class="dashicons <?php echo esc_attr($wpbm_component['icon']); ?>" aria-hidden="true"></span>
                                    <span class="wpbm-component__text">
                                        <strong><?php echo esc_html($wpbm_component['label']); ?></strong>
                                        <small class="wpbm-component__missing"><?php esc_html_e('Not in this bundle', 'wp-bundle-migrator'); ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="wpbm-check wpbm-check--boxed">
                            <input type="checkbox" name="isolate_conflicts" value="1" checked>
                            <span>
                                <strong><?php esc_html_e('Protect the site from incompatible plugins and themes', 'wp-bundle-migrator'); ?></strong>
                                <small>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: PHP version. */
                                            __('Plugins that would crash on PHP %s are deactivated (files kept), and a crashing active theme is swapped for a default theme.', 'wp-bundle-migrator'),
                                            PHP_VERSION
                                        )
                                    );
                                    ?>
                                </small>
                            </span>
                        </label>
                    </div>

                    <div class="wpbm-card wpbm-card--danger">
                        <h2 class="wpbm-card__title"><span class="wpbm-step">4</span><?php esc_html_e('Restore', 'wp-bundle-migrator'); ?></h2>
                        <ul class="wpbm-list">
                            <li><?php esc_html_e('The selected parts of this site are overwritten. This cannot be undone, so create a backup of this site first if you may need it.', 'wp-bundle-migrator'); ?></li>
                            <li><?php esc_html_e('Restoring the database replaces all users. Afterwards, log in with an account from the bundle.', 'wp-bundle-migrator'); ?></li>
                        </ul>
                        <label class="wpbm-check">
                            <input type="checkbox" id="wpbm-restore-confirm">
                            <span><strong><?php esc_html_e('I understand this overwrites this site', 'wp-bundle-migrator'); ?></strong></span>
                        </label>
                        <button type="submit" class="button button-primary button-hero wpbm-button-danger" id="wpbm-restore-start" disabled>
                            <span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e('Restore now', 'wp-bundle-migrator'); ?>
                        </button>
                    </div>
                </form>

                <div id="wpbm-restore-result" hidden tabindex="-1"></div>

                <?php if ($view['hasReport']) : ?>
                    <details class="wpbm-details wpbm-card" id="wpbm-last-report">
                        <summary><?php esc_html_e('Report from the last restore', 'wp-bundle-migrator'); ?></summary>
                        <div id="wpbm-last-report-body"><p class="wpbm-muted"><?php esc_html_e('Loading…', 'wp-bundle-migrator'); ?></p></div>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- BACKUPS -->
    <section class="wpbm-panel" id="wpbm-panel-backups" role="tabpanel" aria-labelledby="wpbm-tab-backups" hidden>
        <div class="wpbm-card">
            <div class="wpbm-toolbar">
                <div>
                    <h2 class="wpbm-card__title"><?php esc_html_e('Saved backups', 'wp-bundle-migrator'); ?></h2>
                    <p class="wpbm-muted" id="wpbm-backups-meta"></p>
                </div>
                <div class="wpbm-toolbar__actions">
                    <label class="screen-reader-text" for="wpbm-backups-search"><?php esc_html_e('Search backups', 'wp-bundle-migrator'); ?></label>
                    <input type="search" id="wpbm-backups-search" placeholder="<?php esc_attr_e('Search…', 'wp-bundle-migrator'); ?>">
                    <button type="button" class="button" id="wpbm-backups-refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e('Refresh', 'wp-bundle-migrator'); ?></button>
                </div>
            </div>

            <div id="wpbm-backups-loading" class="wpbm-loading"><span class="spinner is-active"></span> <?php esc_html_e('Loading backups…', 'wp-bundle-migrator'); ?></div>

            <div class="wpbm-table-wrap">
                <table class="wpbm-table" id="wpbm-backups-table" hidden>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Backup', 'wp-bundle-migrator'); ?></th>
                            <th scope="col"><?php esc_html_e('Contents', 'wp-bundle-migrator'); ?></th>
                            <th scope="col" class="wpbm-num"><?php esc_html_e('Size', 'wp-bundle-migrator'); ?></th>
                            <th scope="col"><?php esc_html_e('Created', 'wp-bundle-migrator'); ?></th>
                            <th scope="col"><?php esc_html_e('Stored on', 'wp-bundle-migrator'); ?></th>
                            <th scope="col" class="wpbm-actions-col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'wp-bundle-migrator'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody id="wpbm-backups-body"></tbody>
                </table>
            </div>

            <div class="wpbm-empty" id="wpbm-backups-empty" hidden>
                <span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
                <h3 id="wpbm-backups-empty-title"><?php esc_html_e('No backups yet', 'wp-bundle-migrator'); ?></h3>
                <p class="wpbm-muted"><?php esc_html_e('Backups you create or upload appear here.', 'wp-bundle-migrator'); ?></p>
                <button type="button" class="button button-primary" data-go-tab="export"><?php esc_html_e('Create a backup', 'wp-bundle-migrator'); ?></button>
            </div>
        </div>
    </section>

    <!-- SETTINGS -->
    <section class="wpbm-panel" id="wpbm-panel-settings" role="tabpanel" aria-labelledby="wpbm-tab-settings" hidden>
        <?php
        $wpbm_s        = $view['settings'];
        $wpbm_cloud    = $wpbm_s['cloud'];
        $wpbm_sched    = $wpbm_s['schedule'];
        $wpbm_datetime = (string) get_option('date_format') . ' ' . (string) get_option('time_format');
        ?>
        <div class="wpbm-settings-layout">
        <nav class="wpbm-settings-nav" aria-label="<?php esc_attr_e('Settings sections', 'wp-bundle-migrator'); ?>">
            <a href="#wpbm-set-cloud"><span class="dashicons dashicons-cloud" aria-hidden="true"></span><?php esc_html_e('Cloud storage', 'wp-bundle-migrator'); ?></a>
            <a href="#wpbm-set-peer"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><?php esc_html_e('Site-to-site transfer', 'wp-bundle-migrator'); ?></a>
            <a href="#wpbm-set-schedule"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e('Scheduled backups', 'wp-bundle-migrator'); ?></a>
            <a href="#wpbm-set-encryption"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Encryption', 'wp-bundle-migrator'); ?></a>
            <a href="#wpbm-set-general"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php esc_html_e('General', 'wp-bundle-migrator'); ?></a>
        </nav>
        <div class="wpbm-settings-forms">

        <!-- Cloud storage -->
        <form class="wpbm-card wpbm-settings" id="wpbm-set-cloud" data-section="cloud" novalidate>
            <h2 class="wpbm-card__title"><span class="dashicons dashicons-cloud" aria-hidden="true"></span><?php esc_html_e('Cloud storage', 'wp-bundle-migrator'); ?></h2>
            <p class="wpbm-muted"><?php esc_html_e('Use your own storage account. Backups go straight from this server to your bucket; nothing passes through us, and there is no extra charge.', 'wp-bundle-migrator'); ?></p>

            <label class="wpbm-field">
                <span class="wpbm-field__label"><?php esc_html_e('Provider', 'wp-bundle-migrator'); ?></span>
                <select name="provider" id="wpbm-cloud-provider">
                    <option value=""><?php esc_html_e('— Not used —', 'wp-bundle-migrator'); ?></option>
                    <?php foreach ($view['providers'] as $wpbm_pid => $wpbm_provider) : ?>
                        <option value="<?php echo esc_attr($wpbm_pid); ?>" <?php selected($wpbm_cloud['provider'], $wpbm_pid); ?>><?php echo esc_html($wpbm_provider['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div id="wpbm-cloud-fields" <?php echo $wpbm_cloud['provider'] === '' ? 'hidden' : ''; ?>>
                <p class="wpbm-provider-links" id="wpbm-provider-links"></p>

                <!-- S3-compatible -->
                <div data-cloud-kind="s3">
                    <div class="wpbm-grid-2">
                        <label class="wpbm-field" data-cloud-field="account">
                            <span class="wpbm-field__label"><?php esc_html_e('Cloudflare account ID', 'wp-bundle-migrator'); ?></span>
                            <input type="text" name="account_id" value="<?php echo esc_attr($wpbm_cloud['account_id']); ?>" autocomplete="off">
                        </label>
                        <label class="wpbm-field" data-cloud-field="endpoint">
                            <span class="wpbm-field__label"><?php esc_html_e('Endpoint URL', 'wp-bundle-migrator'); ?></span>
                            <input type="url" name="endpoint" value="<?php echo esc_attr($wpbm_cloud['endpoint']); ?>" placeholder="https://minio.example.com:9000">
                        </label>
                        <label class="wpbm-field" data-cloud-field="region">
                            <span class="wpbm-field__label"><?php esc_html_e('Region', 'wp-bundle-migrator'); ?></span>
                            <input type="text" name="region" id="wpbm-cloud-region" value="<?php echo esc_attr($wpbm_cloud['region']); ?>">
                        </label>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Bucket', 'wp-bundle-migrator'); ?></span>
                            <input type="text" name="bucket" value="<?php echo esc_attr($wpbm_cloud['bucket']); ?>" autocomplete="off">
                        </label>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Access key ID', 'wp-bundle-migrator'); ?></span>
                            <input type="text" name="access_key" value="<?php echo esc_attr($wpbm_cloud['access_key']); ?>" autocomplete="off" spellcheck="false">
                        </label>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Secret access key', 'wp-bundle-migrator'); ?></span>
                            <input type="password" name="secret_key" autocomplete="new-password" placeholder="<?php echo esc_attr(!empty($wpbm_cloud['has_secret']) ? __('Saved — leave empty to keep', 'wp-bundle-migrator') : ''); ?>">
                        </label>
                        <label class="wpbm-check" data-cloud-field="pathStyle">
                            <input type="checkbox" name="path_style" value="1" <?php checked(!empty($wpbm_cloud['path_style'])); ?>>
                            <span><strong><?php esc_html_e('Path-style URLs', 'wp-bundle-migrator'); ?></strong><small><?php esc_html_e('Needed by most self-hosted servers such as MinIO.', 'wp-bundle-migrator'); ?></small></span>
                        </label>
                    </div>
                    <p class="wpbm-muted wpbm-small"><?php esc_html_e('The secret key is stored encrypted with your site\'s security keys. Give the key access to this bucket only, with permission to list, read, write and delete.', 'wp-bundle-migrator'); ?></p>
                </div>

                <!-- SFTP -->
                <div data-cloud-kind="sftp">
                    <div class="wpbm-grid-3">
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Host', 'wp-bundle-migrator'); ?></span>
                            <input type="text" name="sftp_host" value="<?php echo esc_attr($wpbm_cloud['sftp_host']); ?>" placeholder="backup.example.com" autocomplete="off" spellcheck="false">
                        </label>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Port', 'wp-bundle-migrator'); ?></span>
                            <input type="number" name="sftp_port" min="1" max="65535" value="<?php echo esc_attr((string) $wpbm_cloud['sftp_port']); ?>">
                        </label>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Username', 'wp-bundle-migrator'); ?></span>
                            <input type="text" name="sftp_user" value="<?php echo esc_attr($wpbm_cloud['sftp_user']); ?>" autocomplete="off" spellcheck="false">
                        </label>
                    </div>
                    <div class="wpbm-segmented" role="radiogroup" aria-label="<?php esc_attr_e('Sign in with', 'wp-bundle-migrator'); ?>">
                        <label><input type="radio" name="sftp_auth" value="password" <?php checked($wpbm_cloud['sftp_auth'], 'password'); ?>> <span><?php esc_html_e('Password', 'wp-bundle-migrator'); ?></span></label>
                        <label><input type="radio" name="sftp_auth" value="key" <?php checked($wpbm_cloud['sftp_auth'], 'key'); ?>> <span><?php esc_html_e('Private key', 'wp-bundle-migrator'); ?></span></label>
                    </div>
                    <label class="wpbm-field" data-sftp-auth="password">
                        <span class="wpbm-field__label"><?php esc_html_e('Password', 'wp-bundle-migrator'); ?></span>
                        <input type="password" name="sftp_password" autocomplete="new-password" placeholder="<?php echo esc_attr(!empty($wpbm_cloud['has_sftp_password']) ? __('Saved — leave empty to keep', 'wp-bundle-migrator') : ''); ?>">
                    </label>
                    <div data-sftp-auth="key">
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Private key (OpenSSH or PEM)', 'wp-bundle-migrator'); ?></span>
                            <textarea name="sftp_key" rows="4" class="wpbm-key-input" spellcheck="false" autocomplete="off" placeholder="<?php echo esc_attr(!empty($wpbm_cloud['has_sftp_key']) ? __('Saved — leave empty to keep', 'wp-bundle-migrator') : '-----BEGIN OPENSSH PRIVATE KEY-----'); ?>"></textarea>
                        </label>
                        <label class="wpbm-field">
                            <span class="wpbm-field__label"><?php esc_html_e('Key passphrase (if any)', 'wp-bundle-migrator'); ?></span>
                            <input type="password" name="sftp_key_pass" autocomplete="new-password">
                        </label>
                    </div>
                    <input type="hidden" name="sftp_fingerprint" id="wpbm-sftp-fingerprint" value="">
                    <p class="wpbm-muted wpbm-small">
                        <?php if ($wpbm_cloud['sftp_fingerprint'] !== '') : ?>
                            <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: SSH host key fingerprint. */
                                __('Trusted server fingerprint: %s. Connections to a server with a different key are refused.', 'wp-bundle-migrator'),
                                $wpbm_cloud['sftp_fingerprint']
                            ));
                            ?>
                            <label class="wpbm-inline-check"><input type="checkbox" name="sftp_forget_key" value="1"> <?php esc_html_e('Forget host key', 'wp-bundle-migrator'); ?></label>
                        <?php else : ?>
                            <?php esc_html_e('The server\'s fingerprint is remembered on the first successful connection, and later connections to a server with a different key are refused.', 'wp-bundle-migrator'); ?>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Dropbox -->
                <div data-cloud-kind="dropbox">
                    <ol class="wpbm-steps-list">
                        <li><?php echo wp_kses(__('Create an app at <a href="https://www.dropbox.com/developers/apps/create" target="_blank" rel="noopener noreferrer">dropbox.com/developers</a>: choose <strong>Scoped access</strong> and <strong>App folder</strong>.', 'wp-bundle-migrator'), array('a' => array('href' => array(), 'target' => array(), 'rel' => array()), 'strong' => array())); ?></li>
                        <li><?php echo wp_kses(__('On the app\'s <strong>Permissions</strong> tab, tick <code>files.content.write</code> and <code>files.content.read</code>, then click Submit.', 'wp-bundle-migrator'), array('strong' => array(), 'code' => array())); ?></li>
                        <li><?php echo wp_kses(__('Copy the <strong>App key</strong> from the Settings tab and paste it below.', 'wp-bundle-migrator'), array('strong' => array())); ?></li>
                    </ol>
                    <label class="wpbm-field">
                        <span class="wpbm-field__label"><?php esc_html_e('App key', 'wp-bundle-migrator'); ?></span>
                        <input type="text" name="dropbox_app_key" id="wpbm-dropbox-key" value="<?php echo esc_attr($wpbm_cloud['dropbox_app_key']); ?>" autocomplete="off" spellcheck="false">
                    </label>
                    <div class="wpbm-dropbox-status">
                        <?php if (!empty($wpbm_cloud['dropbox_connected'])) : ?>
                            <span class="wpbm-badge wpbm-badge--ok"><?php esc_html_e('Connected', 'wp-bundle-migrator'); ?></span>
                            <span><?php echo esc_html($wpbm_cloud['dropbox_account']); ?></span>
                        <?php else : ?>
                            <span class="wpbm-badge wpbm-badge--info"><?php esc_html_e('Not connected', 'wp-bundle-migrator'); ?></span>
                        <?php endif; ?>
                        <button type="button" class="button" id="wpbm-dropbox-connect"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php echo !empty($wpbm_cloud['dropbox_connected']) ? esc_html__('Reconnect Dropbox', 'wp-bundle-migrator') : esc_html__('Connect Dropbox', 'wp-bundle-migrator'); ?></button>
                    </div>
                    <div class="wpbm-unlock" id="wpbm-dropbox-code-row" hidden>
                        <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                        <div class="wpbm-unlock__body">
                            <strong><?php esc_html_e('Approve the app in the Dropbox tab that opened, then paste the code Dropbox shows you.', 'wp-bundle-migrator'); ?></strong>
                            <div class="wpbm-inline">
                                <label class="screen-reader-text" for="wpbm-dropbox-code"><?php esc_html_e('Dropbox code', 'wp-bundle-migrator'); ?></label>
                                <input type="text" id="wpbm-dropbox-code" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Access code', 'wp-bundle-migrator'); ?>">
                                <button type="button" class="button button-primary" id="wpbm-dropbox-finish"><?php esc_html_e('Finish connecting', 'wp-bundle-migrator'); ?></button>
                            </div>
                        </div>
                    </div>
                    <p class="wpbm-muted wpbm-small"><?php esc_html_e('Bundles are stored in Apps/<your app name>/ in your Dropbox. Only an encrypted refresh token is saved; no app secret is needed.', 'wp-bundle-migrator'); ?></p>
                </div>

                <label class="wpbm-field">
                    <span class="wpbm-field__label" id="wpbm-cloud-path-label"><?php esc_html_e('Folder', 'wp-bundle-migrator'); ?></span>
                    <input type="text" name="path" value="<?php echo esc_attr($wpbm_cloud['path']); ?>">
                </label>

                <div class="wpbm-stack">
                    <label class="wpbm-check"><input type="checkbox" name="auto_upload" value="1" <?php checked(!empty($wpbm_cloud['auto_upload'])); ?>> <span><strong><?php esc_html_e('Upload manual backups by default', 'wp-bundle-migrator'); ?></strong></span></label>
                    <label class="wpbm-check"><input type="checkbox" name="delete_local" value="1" <?php checked(!empty($wpbm_cloud['delete_local'])); ?>> <span><strong><?php esc_html_e('Delete the server copy after uploading, by default', 'wp-bundle-migrator'); ?></strong></span></label>
                </div>
            </div>

            <div class="wpbm-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save cloud settings', 'wp-bundle-migrator'); ?></button>
                <button type="button" class="button" id="wpbm-cloud-test"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('Test connection', 'wp-bundle-migrator'); ?></button>
                <span class="wpbm-test-result" id="wpbm-cloud-test-result" role="status"></span>
            </div>
        </form>

        <!-- Site-to-site transfer -->
        <div class="wpbm-card wpbm-settings" id="wpbm-set-peer">
            <h2 class="wpbm-card__title"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><?php esc_html_e('Site-to-site transfer', 'wp-bundle-migrator'); ?></h2>
            <p class="wpbm-muted"><?php esc_html_e('Lets another site that runs this plugin send its bundle here (push), or fetch a bundle from here (pull), without downloading files to your computer.', 'wp-bundle-migrator'); ?></p>

            <p id="wpbm-peer-status">
                <?php if (!empty($wpbm_s['peer']['has_key'])) : ?>
                    <span class="wpbm-badge wpbm-badge--warn"><?php esc_html_e('Active', 'wp-bundle-migrator'); ?></span>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: date and time. */
                        __('A transfer key is active until %s.', 'wp-bundle-migrator'),
                        wp_date($wpbm_datetime, (int) $wpbm_s['peer']['expires'])
                    ));
                    ?>
                <?php else : ?>
                    <span class="wpbm-badge wpbm-badge--info"><?php esc_html_e('Off', 'wp-bundle-migrator'); ?></span>
                    <?php esc_html_e('No transfer key. Other sites cannot connect.', 'wp-bundle-migrator'); ?>
                <?php endif; ?>
            </p>

            <div class="wpbm-callout wpbm-callout--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e('Anyone with the key can overwrite this site or copy it. Share it only with yourself, and revoke it when the transfer is done.', 'wp-bundle-migrator'); ?></p></div>

            <div class="wpbm-inline">
                <label class="screen-reader-text" for="wpbm-peer-hours"><?php esc_html_e('Key valid for', 'wp-bundle-migrator'); ?></label>
                <select id="wpbm-peer-hours">
                    <option value="1"><?php esc_html_e('Valid for 1 hour', 'wp-bundle-migrator'); ?></option>
                    <option value="24" selected><?php esc_html_e('Valid for 24 hours', 'wp-bundle-migrator'); ?></option>
                    <option value="72"><?php esc_html_e('Valid for 3 days', 'wp-bundle-migrator'); ?></option>
                    <option value="168"><?php esc_html_e('Valid for 7 days', 'wp-bundle-migrator'); ?></option>
                </select>
                <button type="button" class="button button-primary" id="wpbm-peer-generate"><?php esc_html_e('Create transfer key', 'wp-bundle-migrator'); ?></button>
                <button type="button" class="button wpbm-button-ghost-danger" id="wpbm-peer-revoke" <?php disabled(empty($wpbm_s['peer']['has_key'])); ?>><?php esc_html_e('Revoke key', 'wp-bundle-migrator'); ?></button>
            </div>
            <div class="wpbm-field wpbm-peer-key" id="wpbm-peer-key-row" hidden>
                <span class="wpbm-field__label"><?php esc_html_e('Transfer key (shown once — copy it now)', 'wp-bundle-migrator'); ?></span>
                <textarea id="wpbm-peer-key" rows="3" class="wpbm-key-input" readonly></textarea>
                <p><button type="button" class="button" id="wpbm-peer-copy"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e('Copy key', 'wp-bundle-migrator'); ?></button></p>
            </div>
        </div>

        <!-- Schedule -->
        <form class="wpbm-card wpbm-settings" id="wpbm-set-schedule" data-section="schedule" novalidate>
            <h2 class="wpbm-card__title"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e('Scheduled backups', 'wp-bundle-migrator'); ?></h2>

            <div class="wpbm-schedule-status">
                <div>
                    <span class="wpbm-muted wpbm-small"><?php esc_html_e('Next backup', 'wp-bundle-migrator'); ?></span>
                    <strong id="wpbm-next-run"><?php echo $view['nextRun'] ? esc_html(wp_date($wpbm_datetime, (int) $view['nextRun'])) : esc_html__('Not scheduled', 'wp-bundle-migrator'); ?></strong>
                </div>
                <div>
                    <span class="wpbm-muted wpbm-small"><?php esc_html_e('Last scheduled backup', 'wp-bundle-migrator'); ?></span>
                    <strong>
                        <?php
                        $wpbm_last = is_array($view['lastRun']) ? $view['lastRun'] : null;
                        if ($wpbm_last === null) {
                            esc_html_e('None yet', 'wp-bundle-migrator');
                        } else {
                            echo '<span class="wpbm-badge wpbm-badge--' . esc_attr($wpbm_last['status'] === 'done' ? 'ok' : 'fail') . '">' . esc_html($wpbm_last['status'] === 'done' ? __('OK', 'wp-bundle-migrator') : __('Failed', 'wp-bundle-migrator')) . '</span> ';
                            echo esc_html(wp_date($wpbm_datetime, (int) $wpbm_last['time']));
                            if ($wpbm_last['status'] !== 'done' && !empty($wpbm_last['error'])) {
                                echo '<small class="wpbm-block wpbm-error-text">' . esc_html($wpbm_last['error']) . '</small>';
                            }
                        }
                        ?>
                    </strong>
                </div>
            </div>

            <label class="wpbm-check wpbm-check--boxed">
                <input type="checkbox" name="enabled" value="1" <?php checked(!empty($wpbm_sched['enabled'])); ?>>
                <span><strong><?php esc_html_e('Back up automatically', 'wp-bundle-migrator'); ?></strong><small><?php esc_html_e('Runs through WP-Cron in the background. Check the System tab to make sure WP-Cron is running.', 'wp-bundle-migrator'); ?></small></span>
            </label>

            <div class="wpbm-grid-3">
                <label class="wpbm-field">
                    <span class="wpbm-field__label"><?php esc_html_e('How often', 'wp-bundle-migrator'); ?></span>
                    <select name="frequency" id="wpbm-sched-frequency">
                        <?php foreach ($view['frequencies'] as $wpbm_key => $wpbm_label) : ?>
                            <option value="<?php echo esc_attr($wpbm_key); ?>" <?php selected($wpbm_sched['frequency'], $wpbm_key); ?>><?php echo esc_html($wpbm_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="wpbm-field" id="wpbm-sched-weekday-field">
                    <span class="wpbm-field__label"><?php esc_html_e('Day', 'wp-bundle-migrator'); ?></span>
                    <select name="weekday">
                        <?php
                        global $wp_locale;
                        for ($wpbm_d = 0; $wpbm_d < 7; $wpbm_d++) :
                            ?>
                            <option value="<?php echo esc_attr((string) $wpbm_d); ?>" <?php selected((int) $wpbm_sched['weekday'], $wpbm_d); ?>><?php echo esc_html($wp_locale->get_weekday($wpbm_d)); ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label class="wpbm-field">
                    <span class="wpbm-field__label"><?php esc_html_e('Time (site time zone)', 'wp-bundle-migrator'); ?></span>
                    <input type="time" name="time" value="<?php echo esc_attr($wpbm_sched['time']); ?>">
                </label>
                <label class="wpbm-field">
                    <span class="wpbm-field__label"><?php esc_html_e('What to back up', 'wp-bundle-migrator'); ?></span>
                    <select name="preset">
                        <?php foreach ($wpbm_presets as $wpbm_key => $wpbm_preset) : ?>
                            <?php if ($wpbm_key !== 'custom') : ?>
                                <option value="<?php echo esc_attr($wpbm_key); ?>" <?php selected($wpbm_sched['preset'], $wpbm_key); ?>><?php echo esc_html($wpbm_preset['title']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="wpbm-stack">
                <label class="wpbm-check"><input type="checkbox" name="encrypt" value="1" <?php checked(!empty($wpbm_sched['encrypt'])); ?> <?php disabled(!$view['cryptoReady']); ?>> <span><strong><?php esc_html_e('Encrypt with the saved passphrase', 'wp-bundle-migrator'); ?></strong></span></label>
                <label class="wpbm-check"><input type="checkbox" name="upload" value="1" <?php checked(!empty($wpbm_sched['upload'])); ?>> <span><strong><?php esc_html_e('Upload to cloud storage', 'wp-bundle-migrator'); ?></strong></span></label>
                <label class="wpbm-check wpbm-indent"><input type="checkbox" name="delete_local" value="1" <?php checked(!empty($wpbm_sched['delete_local'])); ?>> <span><strong><?php esc_html_e('Then delete the server copy', 'wp-bundle-migrator'); ?></strong></span></label>
            </div>

            <h3 class="wpbm-subtitle"><?php esc_html_e('Keep (grandfather-father-son rotation)', 'wp-bundle-migrator'); ?></h3>
            <div class="wpbm-grid-3">
                <label class="wpbm-field"><span class="wpbm-field__label"><?php esc_html_e('Daily backups', 'wp-bundle-migrator'); ?></span><input type="number" name="keep_daily" min="1" max="365" value="<?php echo esc_attr((string) $wpbm_sched['keep_daily']); ?>"></label>
                <label class="wpbm-field"><span class="wpbm-field__label"><?php esc_html_e('Weekly backups', 'wp-bundle-migrator'); ?></span><input type="number" name="keep_weekly" min="0" max="104" value="<?php echo esc_attr((string) $wpbm_sched['keep_weekly']); ?>"></label>
                <label class="wpbm-field"><span class="wpbm-field__label"><?php esc_html_e('Monthly backups', 'wp-bundle-migrator'); ?></span><input type="number" name="keep_monthly" min="0" max="120" value="<?php echo esc_attr((string) $wpbm_sched['keep_monthly']); ?>"></label>
            </div>
            <p class="wpbm-muted wpbm-small"><?php esc_html_e('The newest backup of each of the last N days, weeks and months is kept; older scheduled backups are deleted on the server and in cloud storage. Manual backups are never touched by this rule.', 'wp-bundle-migrator'); ?></p>

            <h3 class="wpbm-subtitle"><?php esc_html_e('Notifications', 'wp-bundle-migrator'); ?></h3>
            <div class="wpbm-grid-3">
                <label class="wpbm-field">
                    <span class="wpbm-field__label"><?php esc_html_e('Email me', 'wp-bundle-migrator'); ?></span>
                    <select name="notify_on">
                        <option value="failure" <?php selected($wpbm_sched['notify_on'], 'failure'); ?>><?php esc_html_e('When a backup fails', 'wp-bundle-migrator'); ?></option>
                        <option value="always" <?php selected($wpbm_sched['notify_on'], 'always'); ?>><?php esc_html_e('After every backup', 'wp-bundle-migrator'); ?></option>
                        <option value="never" <?php selected($wpbm_sched['notify_on'], 'never'); ?>><?php esc_html_e('Never', 'wp-bundle-migrator'); ?></option>
                    </select>
                </label>
                <label class="wpbm-field">
                    <span class="wpbm-field__label"><?php esc_html_e('Email address', 'wp-bundle-migrator'); ?></span>
                    <input type="email" name="notify_email" value="<?php echo esc_attr($wpbm_sched['notify_email']); ?>">
                </label>
                <label class="wpbm-field">
                    <span class="wpbm-field__label"><?php esc_html_e('Webhook URL (optional)', 'wp-bundle-migrator'); ?></span>
                    <input type="url" name="webhook" value="<?php echo esc_attr($wpbm_sched['webhook']); ?>" placeholder="https://hooks.slack.com/…">
                </label>
            </div>
            <p class="wpbm-muted wpbm-small"><?php esc_html_e('The webhook receives a JSON POST with event (backup.succeeded or backup.failed), site, message and details. Works with Slack, Discord, Zapier and similar services.', 'wp-bundle-migrator'); ?></p>

            <div class="wpbm-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save schedule', 'wp-bundle-migrator'); ?></button>
                <button type="button" class="button" id="wpbm-schedule-now" <?php disabled(empty($wpbm_sched['enabled'])); ?>><span class="dashicons dashicons-controls-play" aria-hidden="true"></span> <?php esc_html_e('Run scheduled backup now', 'wp-bundle-migrator'); ?></button>
            </div>
        </form>

        <!-- Encryption -->
        <form class="wpbm-card wpbm-settings" id="wpbm-set-encryption" data-section="encryption" novalidate>
            <h2 class="wpbm-card__title"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Encryption', 'wp-bundle-migrator'); ?></h2>
            <?php if (!$view['cryptoReady']) : ?>
                <div class="wpbm-callout wpbm-callout--error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e('This server lacks the PHP OpenSSL extension, so encryption is unavailable.', 'wp-bundle-migrator'); ?></p></div>
            <?php endif; ?>
            <p class="wpbm-muted"><?php esc_html_e('Bundles are encrypted with AES-256-GCM; the key is derived from your passphrase with PBKDF2-SHA256 (310,000 rounds). Any change to an encrypted bundle is detected when restoring.', 'wp-bundle-migrator'); ?></p>

            <p>
                <?php if (!empty($wpbm_s['encryption']['from_constant'])) : ?>
                    <span class="wpbm-badge wpbm-badge--ok"><?php esc_html_e('Set', 'wp-bundle-migrator'); ?></span> <?php esc_html_e('Passphrase comes from the WPBM_ENCRYPTION_PASSPHRASE constant in wp-config.php.', 'wp-bundle-migrator'); ?>
                <?php elseif (!empty($wpbm_s['encryption']['has_passphrase'])) : ?>
                    <span class="wpbm-badge wpbm-badge--ok"><?php esc_html_e('Set', 'wp-bundle-migrator'); ?></span> <?php esc_html_e('A passphrase is saved (stored encrypted with your site\'s security keys).', 'wp-bundle-migrator'); ?>
                <?php else : ?>
                    <span class="wpbm-badge wpbm-badge--info"><?php esc_html_e('Not set', 'wp-bundle-migrator'); ?></span> <?php esc_html_e('Save a passphrase to encrypt scheduled backups.', 'wp-bundle-migrator'); ?>
                <?php endif; ?>
            </p>

            <div class="wpbm-grid-2">
                <label class="wpbm-field">
                    <span class="wpbm-field__label"><?php echo !empty($wpbm_s['encryption']['has_passphrase']) ? esc_html__('New passphrase', 'wp-bundle-migrator') : esc_html__('Passphrase', 'wp-bundle-migrator'); ?></span>
                    <input type="password" name="passphrase" minlength="8" autocomplete="new-password">
                </label>
                <label class="wpbm-field">
                    <span class="wpbm-field__label"><?php esc_html_e('Repeat passphrase', 'wp-bundle-migrator'); ?></span>
                    <input type="password" name="passphrase_confirm" minlength="8" autocomplete="new-password">
                </label>
            </div>
            <div class="wpbm-callout wpbm-callout--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e('Write the passphrase down somewhere safe. If it is lost, encrypted backups cannot be restored by anyone.', 'wp-bundle-migrator'); ?></p></div>
            <div class="wpbm-stack">
                <label class="wpbm-check"><input type="checkbox" name="by_default" value="1" <?php checked(!empty($wpbm_s['encryption']['by_default'])); ?>> <span><strong><?php esc_html_e('Encrypt manual backups by default', 'wp-bundle-migrator'); ?></strong></span></label>
                <?php if (!empty($wpbm_s['encryption']['has_passphrase']) && empty($wpbm_s['encryption']['from_constant'])) : ?>
                    <label class="wpbm-check"><input type="checkbox" name="clear_passphrase" value="1"> <span><strong><?php esc_html_e('Remove the saved passphrase', 'wp-bundle-migrator'); ?></strong></span></label>
                <?php endif; ?>
            </div>
            <div class="wpbm-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save encryption settings', 'wp-bundle-migrator'); ?></button>
            </div>
        </form>

        <!-- General -->
        <form class="wpbm-card wpbm-settings" id="wpbm-set-general" data-section="general" novalidate>
            <h2 class="wpbm-card__title"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php esc_html_e('General', 'wp-bundle-migrator'); ?></h2>

            <label class="wpbm-field">
                <span class="wpbm-field__label"><?php esc_html_e('Keep the newest manual backups', 'wp-bundle-migrator'); ?></span>
                <span class="wpbm-input-suffix">
                    <input type="number" name="retention_count" min="0" max="100" step="1" value="<?php echo esc_attr((string) $view['settings']['retention_count']); ?>">
                    <span><?php esc_html_e('backups of this site', 'wp-bundle-migrator'); ?></span>
                </span>
                <small class="wpbm-field__help"><?php esc_html_e('Applies to manual backups created on this site; older ones are deleted after each new backup. Scheduled backups follow the schedule\'s rotation, and bundles uploaded from other sites are never deleted. 0 keeps everything.', 'wp-bundle-migrator'); ?></small>
            </label>

            <label class="wpbm-field">
                <span class="wpbm-field__label"><?php esc_html_e('Database rows per query', 'wp-bundle-migrator'); ?></span>
                <input type="number" name="chunk_size" min="500" max="10000" step="100" value="<?php echo esc_attr((string) $view['settings']['chunk_size']); ?>">
                <small class="wpbm-field__help"><?php esc_html_e('Lower this if backups fail with memory errors on tables with very large rows. Default: 2500.', 'wp-bundle-migrator'); ?></small>
            </label>

            <div class="wpbm-field">
                <span class="wpbm-field__label"><?php esc_html_e('Backup folder', 'wp-bundle-migrator'); ?></span>
                <code class="wpbm-code"><?php echo esc_html($view['storageDir'] !== '' ? $view['storageDir'] : $view['storageError']); ?></code>
                <small class="wpbm-field__help"><?php esc_html_e('Bundles may contain passwords and personal data. Download them and store them somewhere safe; delete copies you no longer need.', 'wp-bundle-migrator'); ?></small>
            </div>

            <label class="wpbm-field">
                <span class="wpbm-field__label"><?php esc_html_e('Seconds per step', 'wp-bundle-migrator'); ?></span>
                <input type="number" name="step_seconds" min="5" max="120" step="1" value="<?php echo esc_attr((string) $view['settings']['step_seconds']); ?>">
                <small class="wpbm-field__help"><?php esc_html_e('Backups and restores work in steps of this length and continue automatically. Keep it below your host\'s request time limit. Default: 20.', 'wp-bundle-migrator'); ?></small>
            </label>

            <div class="wpbm-field">
                <span class="wpbm-field__label"><?php esc_html_e('Command line', 'wp-bundle-migrator'); ?></span>
                <code class="wpbm-code">wp bundle export [--encrypt] [--upload] · wp bundle import &lt;file&gt; · wp bundle list · wp bundle cloud-test</code>
            </div>

            <div class="wpbm-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save general settings', 'wp-bundle-migrator'); ?></button>
            </div>
        </form>
        </div>
        </div>
    </section>

    <!-- SYSTEM -->
    <section class="wpbm-panel" id="wpbm-panel-system" role="tabpanel" aria-labelledby="wpbm-tab-system" hidden>
        <div class="wpbm-card">
            <div class="wpbm-toolbar">
                <div>
                    <h2 class="wpbm-card__title"><?php esc_html_e('System check', 'wp-bundle-migrator'); ?></h2>
                    <p class="wpbm-muted"><?php esc_html_e('Live values from this server.', 'wp-bundle-migrator'); ?></p>
                </div>
                <div class="wpbm-toolbar__actions">
                    <button type="button" class="button" id="wpbm-copy-report"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e('Copy report', 'wp-bundle-migrator'); ?></button>
                </div>
            </div>
            <ul class="wpbm-checks" id="wpbm-checks">
                <?php
                $wpbm_rows   = $view['checks'];
                $wpbm_rows[] = array(
                    'id'     => 'probe',
                    'label'  => __('Backups hidden from the web', 'wp-bundle-migrator'),
                    'value'  => __('Testing…', 'wp-bundle-migrator'),
                    'status' => 'pending',
                    'help'   => '',
                );
                foreach ($wpbm_rows as $wpbm_row) :
                    ?>
                    <li class="wpbm-check-row is-<?php echo esc_attr($wpbm_row['status']); ?>" data-check="<?php echo esc_attr($wpbm_row['id']); ?>">
                        <span class="wpbm-badge wpbm-badge--<?php echo esc_attr($wpbm_row['status']); ?>"><?php echo esc_html($wpbm_status_labels[$wpbm_row['status']] ?? '…'); ?></span>
                        <span class="wpbm-check-row__label"><?php echo esc_html($wpbm_row['label']); ?></span>
                        <span class="wpbm-check-row__value"><?php echo esc_html($wpbm_row['value']); ?></span>
                        <?php if ($wpbm_row['help'] !== '') : ?>
                            <span class="wpbm-check-row__help"><?php echo esc_html($wpbm_row['help']); ?></span>
                        <?php else : ?>
                            <span class="wpbm-check-row__help" hidden></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <div class="wpbm-toasts" id="wpbm-toasts" aria-live="polite" aria-atomic="false"></div>

    <dialog class="wpbm-dialog" id="wpbm-dialog" aria-labelledby="wpbm-dialog-title">
        <form method="dialog">
            <h2 id="wpbm-dialog-title"></h2>
            <p id="wpbm-dialog-message"></p>
            <div class="wpbm-dialog__actions">
                <button type="submit" class="button" value="cancel"><?php esc_html_e('Cancel', 'wp-bundle-migrator'); ?></button>
                <button type="submit" class="button button-primary" value="confirm" id="wpbm-dialog-confirm"></button>
            </div>
        </form>
    </dialog>
</div>
