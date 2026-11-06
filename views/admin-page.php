<?php
/**
 * Admin screen template.
 *
 * @package SliceBackup
 *
 * @var array<string, mixed> $view Data from Plugin::viewData().
 */

if (!defined('ABSPATH')) {
    exit;
}

$slice_backup_components = $view['components'];
$slice_backup_presets    = array(
    'full'         => array(
        'title' => __('Full site', 'slice-backup'),
        'desc'  => __('Database, media, plugins, themes and config. Best for moving a site.', 'slice-backup'),
        'icon'  => 'dashicons-admin-site-alt3',
    ),
    'turnkey_full' => array(
        'title' => __('Full site + WordPress', 'slice-backup'),
        'desc'  => __('Everything above plus WordPress core files.', 'slice-backup'),
        'icon'  => 'dashicons-wordpress-alt',
    ),
    'db_only'      => array(
        'title' => __('Database only', 'slice-backup'),
        'desc'  => __('Content and settings, no files. Quick safety backup.', 'slice-backup'),
        'icon'  => 'dashicons-database',
    ),
    'media_only'   => array(
        'title' => __('Media only', 'slice-backup'),
        'desc'  => __('Just the uploads folder.', 'slice-backup'),
        'icon'  => 'dashicons-format-gallery',
    ),
    'custom'       => array(
        'title' => __('Custom', 'slice-backup'),
        'desc'  => __('Pick exactly what to include.', 'slice-backup'),
        'icon'  => 'dashicons-admin-settings',
    ),
);
$slice_backup_tabs = array(
    'export'   => array(__('Back up', 'slice-backup'), 'dashicons-upload'),
    'import'   => array(__('Restore', 'slice-backup'), 'dashicons-download'),
    'backups'  => array(__('Backups', 'slice-backup'), 'dashicons-portfolio'),
    'settings' => array(__('Settings', 'slice-backup'), 'dashicons-admin-generic'),
    'system'   => array(__('System', 'slice-backup'), 'dashicons-heart'),
);
$slice_backup_status_labels = array(
    'ok'   => __('OK', 'slice-backup'),
    'warn' => __('Check', 'slice-backup'),
    'fail' => __('Problem', 'slice-backup'),
    'info' => __('Info', 'slice-backup'),
);
$slice_backup_problems = array_filter(
    $view['checks'],
    static function ($row) {
        return $row['status'] === 'fail';
    }
);
?>
<div class="wrap slicebk" id="slicebk-app">
    <header class="slicebk-header">
        <div class="slicebk-header__title">
            <span class="slicebk-header__logo dashicons dashicons-migrate" aria-hidden="true"></span>
            <div>
                <h1><?php esc_html_e('Slice Backup', 'slice-backup'); ?></h1>
                <p class="slicebk-header__sub"><?php esc_html_e('Back up, move and restore this site as a single file.', 'slice-backup'); ?></p>
            </div>
        </div>
        <ul class="slicebk-header__meta">
            <li><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php echo esc_html((string) wp_parse_url($view['siteUrl'], PHP_URL_HOST)); ?></li>
            <li><?php echo esc_html('PHP ' . PHP_VERSION); ?></li>
            <li><?php echo esc_html('v' . SLICE_BACKUP_VERSION); ?></li>
        </ul>
    </header>
    <hr class="wp-header-end">

    <?php if (!empty($slice_backup_problems)) : ?>
        <div class="slicebk-callout slicebk-callout--error" role="alert">
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <div>
                <strong><?php esc_html_e('This server needs attention before backups will work.', 'slice-backup'); ?></strong>
                <p><?php esc_html_e('Open the System tab for details.', 'slice-backup'); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <nav class="slicebk-tabs" role="tablist" aria-label="<?php esc_attr_e('Slice Backup sections', 'slice-backup'); ?>">
        <?php foreach ($slice_backup_tabs as $slice_backup_id => $slice_backup_tab) : ?>
            <button type="button" class="slicebk-tab" role="tab" id="slicebk-tab-<?php echo esc_attr($slice_backup_id); ?>" aria-controls="slicebk-panel-<?php echo esc_attr($slice_backup_id); ?>" aria-selected="false" tabindex="-1" data-tab="<?php echo esc_attr($slice_backup_id); ?>">
                <span class="dashicons <?php echo esc_attr($slice_backup_tab[1]); ?>" aria-hidden="true"></span>
                <?php echo esc_html($slice_backup_tab[0]); ?>
            </button>
        <?php endforeach; ?>
    </nav>

    <!-- JOB PROGRESS (shared by backup, restore, upload and download) -->
    <div class="slicebk-card slicebk-job" id="slicebk-job" hidden aria-live="polite">
        <div class="slicebk-job__head">
            <span class="spinner is-active" aria-hidden="true"></span>
            <div class="slicebk-job__titles">
                <strong id="slicebk-job-title"></strong>
                <span class="slicebk-muted" id="slicebk-job-label"></span>
            </div>
            <span class="slicebk-job__percent" id="slicebk-job-percent">0%</span>
        </div>
        <div class="slicebk-progress__bar" role="progressbar" aria-labelledby="slicebk-job-title" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="slicebk-job-bar"><span style="width:0%"></span></div>
        <div class="slicebk-job__foot">
            <span class="slicebk-muted" id="slicebk-job-detail"></span>
            <span class="slicebk-muted"><span id="slicebk-job-elapsed">0:00</span> · <?php esc_html_e('Runs in the background; you can leave this page.', 'slice-backup'); ?></span>
            <button type="button" class="button-link slicebk-link-danger" id="slicebk-job-cancel"><?php esc_html_e('Cancel', 'slice-backup'); ?></button>
        </div>
    </div>

    <!-- BACK UP -->
    <section class="slicebk-panel" id="slicebk-panel-export" role="tabpanel" aria-labelledby="slicebk-tab-export" hidden>
        <form id="slicebk-export-form" class="slicebk-layout" novalidate>
            <div class="slicebk-layout__main">
                <?php if ($view['isMultisite']) : ?>
                    <div class="slicebk-card">
                        <h2 class="slicebk-card__title"><?php esc_html_e('Which part of the network?', 'slice-backup'); ?></h2>
                        <div class="slicebk-segmented" role="radiogroup">
                            <label><input type="radio" name="multisite_mode" value="entire_network" checked> <span><?php esc_html_e('Entire network', 'slice-backup'); ?></span></label>
                            <label><input type="radio" name="multisite_mode" value="subsite"> <span><?php esc_html_e('One site', 'slice-backup'); ?></span></label>
                        </div>
                        <div id="slicebk-subsite-options" class="slicebk-stack" hidden>
                            <label class="slicebk-field">
                                <span class="slicebk-field__label"><?php esc_html_e('Site', 'slice-backup'); ?></span>
                                <select name="subsite_id" id="slicebk-subsite">
                                    <?php foreach ($view['subsites'] as $slice_backup_site) : ?>
                                        <option value="<?php echo esc_attr((string) $slice_backup_site['id']); ?>"><?php echo esc_html(sprintf('%s — %s', $slice_backup_site['name'] !== '' ? $slice_backup_site['name'] : '#' . $slice_backup_site['id'], $slice_backup_site['url'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="slicebk-check">
                                <input type="checkbox" name="convert_subsite_standalone" value="1" checked>
                                <span>
                                    <strong><?php esc_html_e('Convert to a standalone site', 'slice-backup'); ?></strong>
                                    <small><?php esc_html_e('Rename its tables so the bundle can be restored on a normal, single WordPress site.', 'slice-backup'); ?></small>
                                </span>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="slicebk-card">
                    <h2 class="slicebk-card__title"><?php esc_html_e('What do you want to back up?', 'slice-backup'); ?></h2>
                    <div class="slicebk-presets" role="radiogroup" aria-label="<?php esc_attr_e('Backup type', 'slice-backup'); ?>">
                        <?php foreach ($slice_backup_presets as $slice_backup_key => $slice_backup_preset) : ?>
                            <label class="slicebk-preset">
                                <input type="radio" name="export_type" value="<?php echo esc_attr($slice_backup_key); ?>" <?php checked($slice_backup_key, 'full'); ?>>
                                <span class="slicebk-preset__body">
                                    <span class="dashicons <?php echo esc_attr($slice_backup_preset['icon']); ?>" aria-hidden="true"></span>
                                    <strong><?php echo esc_html($slice_backup_preset['title']); ?></strong>
                                    <small><?php echo esc_html($slice_backup_preset['desc']); ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <details class="slicebk-details" id="slicebk-export-contents">
                        <summary><?php esc_html_e('Contents', 'slice-backup'); ?> <span class="slicebk-muted" id="slicebk-contents-count"></span></summary>
                        <div class="slicebk-components">
                            <?php foreach ($slice_backup_components as $slice_backup_key => $slice_backup_component) : ?>
                                <label class="slicebk-component">
                                    <input type="checkbox" name="include_<?php echo esc_attr($slice_backup_key); ?>" value="1" data-component="<?php echo esc_attr($slice_backup_key); ?>">
                                    <span class="dashicons <?php echo esc_attr($slice_backup_component['icon']); ?>" aria-hidden="true"></span>
                                    <span class="slicebk-component__text">
                                        <strong><?php echo esc_html($slice_backup_component['label']); ?></strong>
                                        <small><?php echo esc_html($slice_backup_component['description']); ?></small>
                                    </span>
                                    <span class="slicebk-component__size" data-size-for="<?php echo esc_attr($slice_backup_key); ?>" aria-label="<?php esc_attr_e('Estimated size', 'slice-backup'); ?>">…</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>

                    <details class="slicebk-details">
                        <summary><?php esc_html_e('Make the backup smaller', 'slice-backup'); ?></summary>
                        <div class="slicebk-stack">
                            <label class="slicebk-check"><input type="checkbox" name="exclude_spam" value="1" checked> <span><strong><?php esc_html_e('Skip spam and trashed comments', 'slice-backup'); ?></strong></span></label>
                            <label class="slicebk-check"><input type="checkbox" name="exclude_revisions" value="1"> <span><strong><?php esc_html_e('Skip post revisions and trashed posts', 'slice-backup'); ?></strong></span></label>
                            <label class="slicebk-check"><input type="checkbox" name="exclude_transients" value="1" checked> <span><strong><?php esc_html_e('Skip cached data (transients)', 'slice-backup'); ?></strong><small><?php esc_html_e('Safe: WordPress rebuilds this cache automatically.', 'slice-backup'); ?></small></span></label>
                        </div>
                    </details>
                </div>

                <div class="slicebk-card">
                    <h2 class="slicebk-card__title"><?php esc_html_e('Protect and store', 'slice-backup'); ?></h2>
                    <div class="slicebk-stack">
                        <label class="slicebk-check slicebk-check--boxed">
                            <input type="checkbox" name="encrypt" value="1" id="slicebk-export-encrypt" <?php checked(!empty($view['settings']['encryption']['by_default'])); ?> <?php disabled(!$view['cryptoReady']); ?>>
                            <span>
                                <strong><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e('Encrypt with AES-256', 'slice-backup'); ?></strong>
                                <small><?php echo $view['cryptoReady'] ? esc_html__('Without the passphrase nobody can open the bundle, including the database password inside it.', 'slice-backup') : esc_html__('Unavailable: this server lacks the PHP OpenSSL extension.', 'slice-backup'); ?></small>
                            </span>
                        </label>
                        <div class="slicebk-indent" id="slicebk-export-passphrase-row" hidden>
                            <?php if (!empty($view['settings']['encryption']['has_passphrase'])) : ?>
                                <p class="slicebk-muted slicebk-small"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e('Uses your saved passphrase. Enter a different one below to override it for this backup.', 'slice-backup'); ?></p>
                            <?php endif; ?>
                            <label class="slicebk-field">
                                <span class="slicebk-field__label"><?php esc_html_e('Passphrase', 'slice-backup'); ?></span>
                                <input type="password" name="passphrase" autocomplete="new-password" minlength="8" placeholder="<?php echo esc_attr(!empty($view['settings']['encryption']['has_passphrase']) ? __('Saved passphrase', 'slice-backup') : __('At least 8 characters', 'slice-backup')); ?>">
                            </label>
                        </div>

                        <label class="slicebk-check slicebk-check--boxed">
                            <input type="checkbox" name="upload" value="1" id="slicebk-export-upload" <?php checked($view['cloudReady'] && !empty($view['settings']['cloud']['auto_upload'])); ?> <?php disabled(!$view['cloudReady']); ?>>
                            <span>
                                <strong><span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span> <?php esc_html_e('Upload to cloud storage', 'slice-backup'); ?></strong>
                                <small>
                                    <?php
                                    if ($view['cloudReady']) {
                                        echo esc_html(sprintf(
                                            /* translators: %s: destination, e.g. "Wasabi · my-bucket". */
                                            __('Sends a copy to %s. Large bundles are uploaded in parts and resume after interruptions.', 'slice-backup'),
                                            $view['cloudLabel']
                                        ));
                                    } else {
                                        esc_html_e('Not set up yet.', 'slice-backup');
                                        echo ' <a href="#settings" data-go-tab="settings">' . esc_html__('Set up cloud storage', 'slice-backup') . '</a>';
                                    }
                                    ?>
                                </small>
                            </span>
                        </label>
                        <label class="slicebk-check slicebk-indent" id="slicebk-export-delete-row" hidden>
                            <input type="checkbox" name="delete_local" value="1" <?php checked(!empty($view['settings']['cloud']['delete_local'])); ?>>
                            <span><strong><?php esc_html_e('Delete the server copy after a successful upload', 'slice-backup'); ?></strong></span>
                        </label>

                        <label class="slicebk-check slicebk-check--boxed">
                            <input type="checkbox" name="push" value="1" id="slicebk-export-push">
                            <span>
                                <strong><span class="dashicons dashicons-randomize" aria-hidden="true"></span> <?php esc_html_e('Send directly to another site', 'slice-backup'); ?></strong>
                                <small><?php esc_html_e('Moves the site without downloading anything. On the other site, install this plugin and create a transfer key under Settings > Site-to-site transfer.', 'slice-backup'); ?></small>
                            </span>
                        </label>
                        <div class="slicebk-indent slicebk-stack" id="slicebk-export-push-row" hidden>
                            <label class="slicebk-field">
                                <span class="slicebk-field__label"><?php esc_html_e('Transfer key of the other site', 'slice-backup'); ?></span>
                                <textarea name="push_key" rows="3" class="slicebk-key-input" spellcheck="false" autocomplete="off" placeholder="slicebk1.…"></textarea>
                            </label>
                            <div class="slicebk-inline">
                                <button type="button" class="button" id="slicebk-push-check"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('Check key', 'slice-backup'); ?></button>
                                <span class="slicebk-test-result" id="slicebk-push-check-result" role="status"></span>
                            </div>
                            <label class="slicebk-check">
                                <input type="checkbox" name="push_restore" value="1" checked>
                                <span>
                                    <strong><?php esc_html_e('Restore it on the other site when it arrives', 'slice-backup'); ?></strong>
                                    <small><?php esc_html_e('Overwrites the other site with this one and updates its addresses. Server config files and WordPress core are never applied automatically.', 'slice-backup'); ?></small>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="slicebk-layout__side">
                <div class="slicebk-card slicebk-summary" aria-live="polite">
                    <h2 class="slicebk-card__title"><?php esc_html_e('Summary', 'slice-backup'); ?></h2>
                    <ul class="slicebk-summary__list" id="slicebk-export-summary"></ul>
                    <p class="slicebk-summary__size">
                        <span><?php esc_html_e('Estimated size (before compression)', 'slice-backup'); ?></span>
                        <strong id="slicebk-export-estimate">…</strong>
                    </p>
                    <div class="slicebk-callout slicebk-callout--warning" id="slicebk-config-warning" hidden>
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <p><?php esc_html_e('Includes wp-config.php, which contains your database password. Keep this bundle private.', 'slice-backup'); ?></p>
                    </div>
                    <p class="slicebk-error-text" id="slicebk-export-empty" hidden><?php esc_html_e('Select at least one item.', 'slice-backup'); ?></p>
                    <button type="submit" class="button button-primary button-hero slicebk-block" id="slicebk-export-start">
                        <span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e('Create backup', 'slice-backup'); ?>
                    </button>
                </div>

                <div class="slicebk-card slicebk-result" id="slicebk-export-result" hidden tabindex="-1">
                    <span class="slicebk-result__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <h2 class="slicebk-card__title"><?php esc_html_e('Backup ready', 'slice-backup'); ?></h2>
                    <p class="slicebk-result__file" id="slicebk-export-result-name"></p>
                    <p class="slicebk-muted" id="slicebk-export-result-size"></p>
                    <p class="slicebk-chips slicebk-chips--center" id="slicebk-export-result-badges"></p>
                    <a class="button button-primary slicebk-block" id="slicebk-export-download" href="#"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Download', 'slice-backup'); ?></a>
                    <p class="slicebk-muted slicebk-small"><?php esc_html_e('To move the site: install WordPress and this plugin on the new server, then upload this file under Restore.', 'slice-backup'); ?></p>
                </div>
            </aside>
        </form>
    </section>

    <!-- RESTORE -->
    <section class="slicebk-panel" id="slicebk-panel-import" role="tabpanel" aria-labelledby="slicebk-tab-import" hidden>
        <div class="slicebk-layout slicebk-layout--narrow">
            <div class="slicebk-layout__main">
                <div class="slicebk-card">
                    <h2 class="slicebk-card__title"><span class="slicebk-step">1</span><?php esc_html_e('Choose a bundle', 'slice-backup'); ?></h2>
                    <div class="slicebk-segmented" role="tablist" aria-label="<?php esc_attr_e('Bundle source', 'slice-backup'); ?>">
                        <button type="button" class="is-active" data-source="upload" aria-pressed="true"><?php esc_html_e('Upload a file', 'slice-backup'); ?></button>
                        <button type="button" data-source="saved" aria-pressed="false"><?php esc_html_e('Use a saved backup', 'slice-backup'); ?></button>
                        <button type="button" data-source="cloud" aria-pressed="false"><?php esc_html_e('From cloud storage', 'slice-backup'); ?></button>
                        <button type="button" data-source="link" aria-pressed="false"><?php esc_html_e('From a link', 'slice-backup'); ?></button>
                        <button type="button" data-source="peer" aria-pressed="false"><?php esc_html_e('From another site', 'slice-backup'); ?></button>
                    </div>

                    <div id="slicebk-source-link" hidden>
                        <p class="slicebk-muted"><?php esc_html_e('Paste a "share" link to a backup file in Google Drive, OneDrive, SharePoint, or Dropbox. Sharing must be set to "Anyone with the link".', 'slice-backup'); ?></p>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Link to the backup file', 'slice-backup'); ?></span>
                            <input type="url" id="slicebk-link-url" class="regular-text" spellcheck="false" autocomplete="off" placeholder="https://drive.google.com/file/d/…">
                        </label>
                        <div class="slicebk-inline">
                            <button type="button" class="button button-primary" id="slicebk-link-start"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Fetch from this link', 'slice-backup'); ?></button>
                            <span class="slicebk-test-result" id="slicebk-link-result" role="status"></span>
                        </div>
                        <p class="slicebk-muted slicebk-small"><?php esc_html_e('The file is downloaded straight to this server. You then choose what to restore below.', 'slice-backup'); ?></p>
                    </div>

                    <div id="slicebk-source-peer" hidden>
                        <p class="slicebk-muted"><?php esc_html_e('Copies another WordPress site to this one. On the other site, install this plugin, create a transfer key under Settings > Site-to-site transfer, and paste it here.', 'slice-backup'); ?></p>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Transfer key of the other site', 'slice-backup'); ?></span>
                            <textarea id="slicebk-pull-key" rows="3" class="slicebk-key-input" spellcheck="false" autocomplete="off" placeholder="slicebk1.…"></textarea>
                        </label>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('What to copy', 'slice-backup'); ?></span>
                            <select id="slicebk-pull-preset">
                                <?php foreach ($slice_backup_presets as $slice_backup_key => $slice_backup_preset) : ?>
                                    <?php if (!in_array($slice_backup_key, array('custom', 'turnkey_full'), true)) : ?>
                                        <option value="<?php echo esc_attr($slice_backup_key); ?>"><?php echo esc_html($slice_backup_preset['title']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="slicebk-inline">
                            <button type="button" class="button button-primary" id="slicebk-pull-start"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Fetch from the other site', 'slice-backup'); ?></button>
                            <span class="slicebk-test-result" id="slicebk-pull-result" role="status"></span>
                        </div>
                        <p class="slicebk-muted slicebk-small"><?php esc_html_e('The bundle is created on the other site and downloaded here. You then choose what to restore below.', 'slice-backup'); ?></p>
                    </div>

                    <div id="slicebk-source-upload">
                        <label class="slicebk-dropzone" id="slicebk-dropzone" for="slicebk-file">
                            <span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
                            <strong><?php esc_html_e('Drop a bundle here or click to browse', 'slice-backup'); ?></strong>
                            <small><?php esc_html_e('.zip files of any size; large files are uploaded in pieces.', 'slice-backup'); ?></small>
                            <input type="file" id="slicebk-file" accept=".zip,.slicebk,application/zip" class="screen-reader-text">
                        </label>
                        <div class="slicebk-progress" id="slicebk-upload-progress" hidden>
                            <div class="slicebk-progress__bar"><span id="slicebk-upload-bar" style="width:0%"></span></div>
                            <div class="slicebk-progress__row">
                                <p class="slicebk-progress__text" id="slicebk-upload-text" aria-live="polite"></p>
                                <button type="button" class="button-link slicebk-link-danger" id="slicebk-upload-cancel"><?php esc_html_e('Cancel', 'slice-backup'); ?></button>
                            </div>
                        </div>
                    </div>

                    <div id="slicebk-source-saved" hidden>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Saved backup', 'slice-backup'); ?></span>
                            <select id="slicebk-saved-select">
                                <option value=""><?php esc_html_e('Select a backup…', 'slice-backup'); ?></option>
                            </select>
                        </label>
                    </div>

                    <div id="slicebk-source-cloud" hidden>
                        <?php if ($view['cloudReady']) : ?>
                            <label class="slicebk-field">
                                <span class="slicebk-field__label"><?php esc_html_e('Bundle in cloud storage', 'slice-backup'); ?></span>
                                <select id="slicebk-cloud-select">
                                    <option value=""><?php esc_html_e('Loading…', 'slice-backup'); ?></option>
                                </select>
                            </label>
                            <button type="button" class="button" id="slicebk-cloud-fetch" disabled><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Download to this server', 'slice-backup'); ?></button>
                        <?php else : ?>
                            <p class="slicebk-muted"><?php esc_html_e('Cloud storage is not set up.', 'slice-backup'); ?> <a href="#settings" data-go-tab="settings"><?php esc_html_e('Set it up', 'slice-backup'); ?></a></p>
                        <?php endif; ?>
                    </div>

                    <div id="slicebk-unlock" class="slicebk-unlock" hidden>
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <div class="slicebk-unlock__body">
                            <strong><?php esc_html_e('This bundle is encrypted', 'slice-backup'); ?></strong>
                            <p class="slicebk-muted slicebk-small" id="slicebk-unlock-error"><?php esc_html_e('Enter the passphrase that was used to create it.', 'slice-backup'); ?></p>
                            <div class="slicebk-inline">
                                <label class="screen-reader-text" for="slicebk-unlock-pass"><?php esc_html_e('Passphrase', 'slice-backup'); ?></label>
                                <input type="password" id="slicebk-unlock-pass" autocomplete="off" placeholder="<?php esc_attr_e('Passphrase', 'slice-backup'); ?>">
                                <button type="button" class="button button-primary" id="slicebk-unlock-btn"><?php esc_html_e('Unlock', 'slice-backup'); ?></button>
                            </div>
                        </div>
                    </div>

                    <div id="slicebk-bundle-details" class="slicebk-bundle-details" hidden></div>
                </div>

                <form id="slicebk-restore-form" novalidate hidden>
                    <div class="slicebk-card">
                        <h2 class="slicebk-card__title"><span class="slicebk-step">2</span><?php esc_html_e('Addresses', 'slice-backup'); ?></h2>
                        <p class="slicebk-muted"><?php esc_html_e('Links in the database that point to the old address are rewritten to this site\'s address. Serialized data stays valid.', 'slice-backup'); ?></p>
                        <div class="slicebk-url-row">
                            <label class="slicebk-field">
                                <span class="slicebk-field__label"><?php esc_html_e('Old address', 'slice-backup'); ?></span>
                                <input type="url" name="old_url" id="slicebk-old-url" class="regular-text" placeholder="https://old-site.example">
                            </label>
                            <span class="dashicons dashicons-arrow-right-alt slicebk-url-row__arrow" aria-hidden="true"></span>
                            <label class="slicebk-field">
                                <span class="slicebk-field__label"><?php esc_html_e('New address', 'slice-backup'); ?></span>
                                <input type="url" name="new_url" id="slicebk-new-url" class="regular-text" value="<?php echo esc_attr(untrailingslashit($view['siteUrl'])); ?>">
                            </label>
                        </div>
                        <?php if ($view['isMultisite']) : ?>
                            <div class="slicebk-url-row" id="slicebk-network-fields" hidden>
                                <label class="slicebk-field">
                                    <span class="slicebk-field__label"><?php esc_html_e('Old network domain', 'slice-backup'); ?></span>
                                    <input type="text" name="old_network_domain" id="slicebk-old-domain" class="regular-text">
                                </label>
                                <span class="dashicons dashicons-arrow-right-alt slicebk-url-row__arrow" aria-hidden="true"></span>
                                <label class="slicebk-field">
                                    <span class="slicebk-field__label"><?php esc_html_e('New network domain', 'slice-backup'); ?></span>
                                    <input type="text" name="new_network_domain" id="slicebk-new-domain" class="regular-text" value="<?php echo esc_attr(defined('DOMAIN_CURRENT_SITE') ? DOMAIN_CURRENT_SITE : ''); ?>">
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="slicebk-card">
                        <h2 class="slicebk-card__title"><span class="slicebk-step">3</span><?php esc_html_e('What to restore', 'slice-backup'); ?></h2>
                        <div class="slicebk-components slicebk-components--compact">
                            <?php foreach ($slice_backup_components as $slice_backup_key => $slice_backup_component) : ?>
                                <label class="slicebk-component">
                                    <input type="checkbox" name="restore_<?php echo esc_attr($slice_backup_key); ?>" value="1" data-restore="<?php echo esc_attr($slice_backup_key); ?>">
                                    <span class="dashicons <?php echo esc_attr($slice_backup_component['icon']); ?>" aria-hidden="true"></span>
                                    <span class="slicebk-component__text">
                                        <strong><?php echo esc_html($slice_backup_component['label']); ?></strong>
                                        <small class="slicebk-component__missing"><?php esc_html_e('Not in this bundle', 'slice-backup'); ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="slicebk-check slicebk-check--boxed">
                            <input type="checkbox" name="isolate_conflicts" value="1" checked>
                            <span>
                                <strong><?php esc_html_e('Protect the site from incompatible plugins and themes', 'slice-backup'); ?></strong>
                                <small>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: PHP version. */
                                            __('Plugins that would crash on PHP %s are deactivated (files kept), and a crashing active theme is swapped for a default theme.', 'slice-backup'),
                                            PHP_VERSION
                                        )
                                    );
                                    ?>
                                </small>
                            </span>
                        </label>
                    </div>

                    <div class="slicebk-card slicebk-card--danger">
                        <h2 class="slicebk-card__title"><span class="slicebk-step">4</span><?php esc_html_e('Restore', 'slice-backup'); ?></h2>
                        <ul class="slicebk-list">
                            <li><?php esc_html_e('The database and plugin/theme folders can be undone afterwards with "Undo this restore". Uploads, WordPress core, languages and loose root files are copied in place and cannot be undone, so back up this site first if you may need them.', 'slice-backup'); ?></li>
                            <li><?php esc_html_e('Restoring the database replaces all users. Afterwards, log in with an account from the bundle.', 'slice-backup'); ?></li>
                        </ul>
                        <label class="slicebk-check">
                            <input type="checkbox" id="slicebk-restore-confirm">
                            <span><strong><?php esc_html_e('I understand this overwrites this site', 'slice-backup'); ?></strong></span>
                        </label>
                        <button type="submit" class="button button-primary button-hero slicebk-button-danger" id="slicebk-restore-start" disabled>
                            <span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e('Restore now', 'slice-backup'); ?>
                        </button>
                    </div>
                </form>

                <div id="slicebk-rollback-banner" class="slicebk-card slicebk-callout slicebk-callout--info" <?php echo empty($view['rollback']) ? 'hidden' : ''; ?>>
                    <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                    <div class="slicebk-rollback-body">
                        <p id="slicebk-rollback-text">
                            <?php if (!empty($view['rollback'])) : ?>
                                <?php
                                printf(
                                    /* translators: 1: date, 2: number of tables, 3: number of folders. */
                                    esc_html__('A safety copy from the restore on %1$s is available (%2$d database tables, %3$d plugin/theme folders).', 'slice-backup'),
                                    esc_html($view['rollback']['when']),
                                    (int) $view['rollback']['tables'],
                                    (int) $view['rollback']['folders']
                                );
                                ?>
                            <?php endif; ?>
                        </p>
                        <div class="slicebk-inline">
                            <button type="button" class="button button-primary" id="slicebk-rollback-undo"><?php esc_html_e('Undo this restore', 'slice-backup'); ?></button>
                            <button type="button" class="button" id="slicebk-rollback-discard"><?php esc_html_e('Delete safety copy', 'slice-backup'); ?></button>
                        </div>
                    </div>
                </div>

                <div id="slicebk-restore-result" hidden tabindex="-1"></div>

                <?php if ($view['hasReport']) : ?>
                    <details class="slicebk-details slicebk-card" id="slicebk-last-report">
                        <summary><?php esc_html_e('Report from the last restore', 'slice-backup'); ?></summary>
                        <div id="slicebk-last-report-body"><p class="slicebk-muted"><?php esc_html_e('Loading…', 'slice-backup'); ?></p></div>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- BACKUPS -->
    <section class="slicebk-panel" id="slicebk-panel-backups" role="tabpanel" aria-labelledby="slicebk-tab-backups" hidden>
        <div class="slicebk-card">
            <div class="slicebk-toolbar">
                <div>
                    <h2 class="slicebk-card__title"><?php esc_html_e('Saved backups', 'slice-backup'); ?></h2>
                    <p class="slicebk-muted" id="slicebk-backups-meta"></p>
                </div>
                <div class="slicebk-toolbar__actions">
                    <label class="screen-reader-text" for="slicebk-backups-search"><?php esc_html_e('Search backups', 'slice-backup'); ?></label>
                    <input type="search" id="slicebk-backups-search" placeholder="<?php esc_attr_e('Search…', 'slice-backup'); ?>">
                    <button type="button" class="button" id="slicebk-backups-refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e('Refresh', 'slice-backup'); ?></button>
                </div>
            </div>

            <div id="slicebk-backups-loading" class="slicebk-loading"><span class="spinner is-active"></span> <?php esc_html_e('Loading backups…', 'slice-backup'); ?></div>

            <div class="slicebk-table-wrap">
                <table class="slicebk-table" id="slicebk-backups-table" hidden>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Backup', 'slice-backup'); ?></th>
                            <th scope="col"><?php esc_html_e('Contents', 'slice-backup'); ?></th>
                            <th scope="col" class="slicebk-num"><?php esc_html_e('Size', 'slice-backup'); ?></th>
                            <th scope="col"><?php esc_html_e('Created', 'slice-backup'); ?></th>
                            <th scope="col"><?php esc_html_e('Stored on', 'slice-backup'); ?></th>
                            <th scope="col" class="slicebk-actions-col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'slice-backup'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody id="slicebk-backups-body"></tbody>
                </table>
            </div>

            <div class="slicebk-empty" id="slicebk-backups-empty" hidden>
                <span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
                <h3 id="slicebk-backups-empty-title"><?php esc_html_e('No backups yet', 'slice-backup'); ?></h3>
                <p class="slicebk-muted"><?php esc_html_e('Backups you create or upload appear here.', 'slice-backup'); ?></p>
                <button type="button" class="button button-primary" data-go-tab="export"><?php esc_html_e('Create a backup', 'slice-backup'); ?></button>
            </div>
        </div>
    </section>

    <!-- SETTINGS -->
    <section class="slicebk-panel" id="slicebk-panel-settings" role="tabpanel" aria-labelledby="slicebk-tab-settings" hidden>
        <?php
        $slice_backup_s        = $view['settings'];
        $slice_backup_cloud    = $slice_backup_s['cloud'];
        $slice_backup_sched    = $slice_backup_s['schedule'];
        $slice_backup_datetime = (string) get_option('date_format') . ' ' . (string) get_option('time_format');
        ?>
        <div class="slicebk-settings-layout">
        <nav class="slicebk-settings-nav" aria-label="<?php esc_attr_e('Settings sections', 'slice-backup'); ?>">
            <a href="#slicebk-set-cloud"><span class="dashicons dashicons-cloud" aria-hidden="true"></span><?php esc_html_e('Cloud storage', 'slice-backup'); ?></a>
            <a href="#slicebk-set-peer"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><?php esc_html_e('Site-to-site transfer', 'slice-backup'); ?></a>
            <a href="#slicebk-set-schedule"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e('Scheduled backups', 'slice-backup'); ?></a>
            <a href="#slicebk-set-encryption"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Encryption', 'slice-backup'); ?></a>
            <a href="#slicebk-set-general"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php esc_html_e('General', 'slice-backup'); ?></a>
        </nav>
        <div class="slicebk-settings-forms">

        <!-- Cloud storage -->
        <form class="slicebk-card slicebk-settings" id="slicebk-set-cloud" data-section="cloud" novalidate>
            <h2 class="slicebk-card__title"><span class="dashicons dashicons-cloud" aria-hidden="true"></span><?php esc_html_e('Cloud storage', 'slice-backup'); ?></h2>
            <p class="slicebk-muted"><?php esc_html_e('Use your own storage account. Backups go straight from this server to your bucket; nothing passes through us, and there is no extra charge.', 'slice-backup'); ?></p>

            <label class="slicebk-field">
                <span class="slicebk-field__label"><?php esc_html_e('Provider', 'slice-backup'); ?></span>
                <select name="provider" id="slicebk-cloud-provider">
                    <option value=""><?php esc_html_e('— Not used —', 'slice-backup'); ?></option>
                    <?php foreach ($view['providers'] as $slice_backup_pid => $slice_backup_provider) : ?>
                        <option value="<?php echo esc_attr($slice_backup_pid); ?>" <?php selected($slice_backup_cloud['provider'], $slice_backup_pid); ?>><?php echo esc_html($slice_backup_provider['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div id="slicebk-cloud-fields" <?php echo $slice_backup_cloud['provider'] === '' ? 'hidden' : ''; ?>>
                <p class="slicebk-provider-links" id="slicebk-provider-links"></p>

                <!-- S3-compatible -->
                <div data-cloud-kind="s3">
                    <div class="slicebk-grid-2">
                        <label class="slicebk-field" data-cloud-field="account">
                            <span class="slicebk-field__label"><?php esc_html_e('Cloudflare account ID', 'slice-backup'); ?></span>
                            <input type="text" name="account_id" value="<?php echo esc_attr($slice_backup_cloud['account_id']); ?>" autocomplete="off">
                        </label>
                        <label class="slicebk-field" data-cloud-field="endpoint">
                            <span class="slicebk-field__label"><?php esc_html_e('Endpoint URL', 'slice-backup'); ?></span>
                            <input type="url" name="endpoint" value="<?php echo esc_attr($slice_backup_cloud['endpoint']); ?>" placeholder="https://minio.example.com:9000">
                        </label>
                        <label class="slicebk-field" data-cloud-field="region">
                            <span class="slicebk-field__label"><?php esc_html_e('Region', 'slice-backup'); ?></span>
                            <input type="text" name="region" id="slicebk-cloud-region" value="<?php echo esc_attr($slice_backup_cloud['region']); ?>">
                        </label>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Bucket', 'slice-backup'); ?></span>
                            <input type="text" name="bucket" value="<?php echo esc_attr($slice_backup_cloud['bucket']); ?>" autocomplete="off">
                        </label>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Access key ID', 'slice-backup'); ?></span>
                            <input type="text" name="access_key" value="<?php echo esc_attr($slice_backup_cloud['access_key']); ?>" autocomplete="off" spellcheck="false">
                        </label>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Secret access key', 'slice-backup'); ?></span>
                            <input type="password" name="secret_key" autocomplete="new-password" placeholder="<?php echo esc_attr(!empty($slice_backup_cloud['has_secret']) ? __('Saved — leave empty to keep', 'slice-backup') : ''); ?>">
                        </label>
                        <label class="slicebk-check" data-cloud-field="pathStyle">
                            <input type="checkbox" name="path_style" value="1" <?php checked(!empty($slice_backup_cloud['path_style'])); ?>>
                            <span><strong><?php esc_html_e('Path-style URLs', 'slice-backup'); ?></strong><small><?php esc_html_e('Needed by most self-hosted servers such as MinIO.', 'slice-backup'); ?></small></span>
                        </label>
                    </div>
                    <p class="slicebk-muted slicebk-small"><?php esc_html_e('The secret key is stored encrypted with your site\'s security keys. Give the key access to this bucket only, with permission to list, read, write and delete.', 'slice-backup'); ?></p>
                </div>

                <!-- SFTP -->
                <div data-cloud-kind="sftp">
                    <div class="slicebk-grid-3">
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Host', 'slice-backup'); ?></span>
                            <input type="text" name="sftp_host" value="<?php echo esc_attr($slice_backup_cloud['sftp_host']); ?>" placeholder="backup.example.com" autocomplete="off" spellcheck="false">
                        </label>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Port', 'slice-backup'); ?></span>
                            <input type="number" name="sftp_port" min="1" max="65535" value="<?php echo esc_attr((string) $slice_backup_cloud['sftp_port']); ?>">
                        </label>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Username', 'slice-backup'); ?></span>
                            <input type="text" name="sftp_user" value="<?php echo esc_attr($slice_backup_cloud['sftp_user']); ?>" autocomplete="off" spellcheck="false">
                        </label>
                    </div>
                    <div class="slicebk-segmented" role="radiogroup" aria-label="<?php esc_attr_e('Sign in with', 'slice-backup'); ?>">
                        <label><input type="radio" name="sftp_auth" value="password" <?php checked($slice_backup_cloud['sftp_auth'], 'password'); ?>> <span><?php esc_html_e('Password', 'slice-backup'); ?></span></label>
                        <label><input type="radio" name="sftp_auth" value="key" <?php checked($slice_backup_cloud['sftp_auth'], 'key'); ?>> <span><?php esc_html_e('Private key', 'slice-backup'); ?></span></label>
                    </div>
                    <label class="slicebk-field" data-sftp-auth="password">
                        <span class="slicebk-field__label"><?php esc_html_e('Password', 'slice-backup'); ?></span>
                        <input type="password" name="sftp_password" autocomplete="new-password" placeholder="<?php echo esc_attr(!empty($slice_backup_cloud['has_sftp_password']) ? __('Saved — leave empty to keep', 'slice-backup') : ''); ?>">
                    </label>
                    <div data-sftp-auth="key">
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Private key (OpenSSH or PEM)', 'slice-backup'); ?></span>
                            <textarea name="sftp_key" rows="4" class="slicebk-key-input" spellcheck="false" autocomplete="off" placeholder="<?php echo esc_attr(!empty($slice_backup_cloud['has_sftp_key']) ? __('Saved — leave empty to keep', 'slice-backup') : '-----BEGIN OPENSSH PRIVATE KEY-----'); ?>"></textarea>
                        </label>
                        <label class="slicebk-field">
                            <span class="slicebk-field__label"><?php esc_html_e('Key passphrase (if any)', 'slice-backup'); ?></span>
                            <input type="password" name="sftp_key_pass" autocomplete="new-password">
                        </label>
                    </div>
                    <input type="hidden" name="sftp_fingerprint" id="slicebk-sftp-fingerprint" value="">
                    <p class="slicebk-muted slicebk-small">
                        <?php if ($slice_backup_cloud['sftp_fingerprint'] !== '') : ?>
                            <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: SSH host key fingerprint. */
                                __('Trusted server fingerprint: %s. Connections to a server with a different key are refused.', 'slice-backup'),
                                $slice_backup_cloud['sftp_fingerprint']
                            ));
                            ?>
                            <label class="slicebk-inline-check"><input type="checkbox" name="sftp_forget_key" value="1"> <?php esc_html_e('Forget host key', 'slice-backup'); ?></label>
                        <?php else : ?>
                            <?php esc_html_e('The server\'s fingerprint is remembered on the first successful connection, and later connections to a server with a different key are refused.', 'slice-backup'); ?>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Dropbox -->
                <div data-cloud-kind="dropbox">
                    <ol class="slicebk-steps-list">
                        <li><?php echo wp_kses(__('Create an app at <a href="https://www.dropbox.com/developers/apps/create" target="_blank" rel="noopener noreferrer">dropbox.com/developers</a>: choose <strong>Scoped access</strong> and <strong>App folder</strong>.', 'slice-backup'), array('a' => array('href' => array(), 'target' => array(), 'rel' => array()), 'strong' => array())); ?></li>
                        <li><?php echo wp_kses(__('On the app\'s <strong>Permissions</strong> tab, tick <code>files.content.write</code> and <code>files.content.read</code>, then click Submit.', 'slice-backup'), array('strong' => array(), 'code' => array())); ?></li>
                        <li><?php echo wp_kses(__('Copy the <strong>App key</strong> from the Settings tab and paste it below.', 'slice-backup'), array('strong' => array())); ?></li>
                    </ol>
                    <label class="slicebk-field">
                        <span class="slicebk-field__label"><?php esc_html_e('App key', 'slice-backup'); ?></span>
                        <input type="text" name="dropbox_app_key" id="slicebk-dropbox-key" value="<?php echo esc_attr($slice_backup_cloud['dropbox_app_key']); ?>" autocomplete="off" spellcheck="false">
                    </label>
                    <div class="slicebk-dropbox-status">
                        <?php if (!empty($slice_backup_cloud['dropbox_connected'])) : ?>
                            <span class="slicebk-badge slicebk-badge--ok"><?php esc_html_e('Connected', 'slice-backup'); ?></span>
                            <span><?php echo esc_html($slice_backup_cloud['dropbox_account']); ?></span>
                        <?php else : ?>
                            <span class="slicebk-badge slicebk-badge--info"><?php esc_html_e('Not connected', 'slice-backup'); ?></span>
                        <?php endif; ?>
                        <button type="button" class="button" id="slicebk-dropbox-connect"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php echo !empty($slice_backup_cloud['dropbox_connected']) ? esc_html__('Reconnect Dropbox', 'slice-backup') : esc_html__('Connect Dropbox', 'slice-backup'); ?></button>
                    </div>
                    <div class="slicebk-unlock" id="slicebk-dropbox-code-row" hidden>
                        <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                        <div class="slicebk-unlock__body">
                            <strong><?php esc_html_e('Approve the app in the Dropbox tab that opened, then paste the code Dropbox shows you.', 'slice-backup'); ?></strong>
                            <div class="slicebk-inline">
                                <label class="screen-reader-text" for="slicebk-dropbox-code"><?php esc_html_e('Dropbox code', 'slice-backup'); ?></label>
                                <input type="text" id="slicebk-dropbox-code" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Access code', 'slice-backup'); ?>">
                                <button type="button" class="button button-primary" id="slicebk-dropbox-finish"><?php esc_html_e('Finish connecting', 'slice-backup'); ?></button>
                            </div>
                        </div>
                    </div>
                    <p class="slicebk-muted slicebk-small"><?php esc_html_e('Bundles are stored in Apps/<your app name>/ in your Dropbox. Only an encrypted refresh token is saved; no app secret is needed.', 'slice-backup'); ?></p>
                </div>

                <label class="slicebk-field">
                    <span class="slicebk-field__label" id="slicebk-cloud-path-label"><?php esc_html_e('Folder', 'slice-backup'); ?></span>
                    <input type="text" name="path" value="<?php echo esc_attr($slice_backup_cloud['path']); ?>">
                </label>

                <div class="slicebk-stack">
                    <label class="slicebk-check"><input type="checkbox" name="auto_upload" value="1" <?php checked(!empty($slice_backup_cloud['auto_upload'])); ?>> <span><strong><?php esc_html_e('Upload manual backups by default', 'slice-backup'); ?></strong></span></label>
                    <label class="slicebk-check"><input type="checkbox" name="delete_local" value="1" <?php checked(!empty($slice_backup_cloud['delete_local'])); ?>> <span><strong><?php esc_html_e('Delete the server copy after uploading, by default', 'slice-backup'); ?></strong></span></label>
                </div>
            </div>

            <div class="slicebk-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save cloud settings', 'slice-backup'); ?></button>
                <button type="button" class="button" id="slicebk-cloud-test"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('Test connection', 'slice-backup'); ?></button>
                <span class="slicebk-test-result" id="slicebk-cloud-test-result" role="status"></span>
            </div>
        </form>

        <!-- Site-to-site transfer -->
        <div class="slicebk-card slicebk-settings" id="slicebk-set-peer">
            <h2 class="slicebk-card__title"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><?php esc_html_e('Site-to-site transfer', 'slice-backup'); ?></h2>
            <p class="slicebk-muted"><?php esc_html_e('Lets another site that runs this plugin send its bundle here (push), or fetch a bundle from here (pull), without downloading files to your computer.', 'slice-backup'); ?></p>

            <p id="slicebk-peer-status">
                <?php if (!empty($slice_backup_s['peer']['has_key'])) : ?>
                    <span class="slicebk-badge slicebk-badge--warn"><?php esc_html_e('Active', 'slice-backup'); ?></span>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: date and time. */
                        __('A transfer key is active until %s.', 'slice-backup'),
                        wp_date($slice_backup_datetime, (int) $slice_backup_s['peer']['expires'])
                    ));
                    ?>
                <?php else : ?>
                    <span class="slicebk-badge slicebk-badge--info"><?php esc_html_e('Off', 'slice-backup'); ?></span>
                    <?php esc_html_e('No transfer key. Other sites cannot connect.', 'slice-backup'); ?>
                <?php endif; ?>
            </p>

            <div class="slicebk-callout slicebk-callout--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e('Anyone with the key can overwrite this site or copy it. Share it only with yourself, and revoke it when the transfer is done.', 'slice-backup'); ?></p></div>

            <div class="slicebk-inline">
                <label class="screen-reader-text" for="slicebk-peer-hours"><?php esc_html_e('Key valid for', 'slice-backup'); ?></label>
                <select id="slicebk-peer-hours">
                    <option value="1"><?php esc_html_e('Valid for 1 hour', 'slice-backup'); ?></option>
                    <option value="24" selected><?php esc_html_e('Valid for 24 hours', 'slice-backup'); ?></option>
                    <option value="72"><?php esc_html_e('Valid for 3 days', 'slice-backup'); ?></option>
                    <option value="168"><?php esc_html_e('Valid for 7 days', 'slice-backup'); ?></option>
                </select>
                <button type="button" class="button button-primary" id="slicebk-peer-generate"><?php esc_html_e('Create transfer key', 'slice-backup'); ?></button>
                <button type="button" class="button slicebk-button-ghost-danger" id="slicebk-peer-revoke" <?php disabled(empty($slice_backup_s['peer']['has_key'])); ?>><?php esc_html_e('Revoke key', 'slice-backup'); ?></button>
            </div>
            <div class="slicebk-field slicebk-peer-key" id="slicebk-peer-key-row" hidden>
                <span class="slicebk-field__label"><?php esc_html_e('Transfer key (shown once — copy it now)', 'slice-backup'); ?></span>
                <textarea id="slicebk-peer-key" rows="3" class="slicebk-key-input" readonly></textarea>
                <p><button type="button" class="button" id="slicebk-peer-copy"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e('Copy key', 'slice-backup'); ?></button></p>
            </div>
        </div>

        <!-- Schedule -->
        <form class="slicebk-card slicebk-settings" id="slicebk-set-schedule" data-section="schedule" novalidate>
            <h2 class="slicebk-card__title"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e('Scheduled backups', 'slice-backup'); ?></h2>

            <div class="slicebk-schedule-status">
                <div>
                    <span class="slicebk-muted slicebk-small"><?php esc_html_e('Next backup', 'slice-backup'); ?></span>
                    <strong id="slicebk-next-run"><?php echo $view['nextRun'] ? esc_html(wp_date($slice_backup_datetime, (int) $view['nextRun'])) : esc_html__('Not scheduled', 'slice-backup'); ?></strong>
                </div>
                <div>
                    <span class="slicebk-muted slicebk-small"><?php esc_html_e('Last scheduled backup', 'slice-backup'); ?></span>
                    <strong>
                        <?php
                        $slice_backup_last = is_array($view['lastRun']) ? $view['lastRun'] : null;
                        if ($slice_backup_last === null) {
                            esc_html_e('None yet', 'slice-backup');
                        } else {
                            echo '<span class="slicebk-badge slicebk-badge--' . esc_attr($slice_backup_last['status'] === 'done' ? 'ok' : 'fail') . '">' . esc_html($slice_backup_last['status'] === 'done' ? __('OK', 'slice-backup') : __('Failed', 'slice-backup')) . '</span> ';
                            echo esc_html(wp_date($slice_backup_datetime, (int) $slice_backup_last['time']));
                            if ($slice_backup_last['status'] !== 'done' && !empty($slice_backup_last['error'])) {
                                echo '<small class="slicebk-block slicebk-error-text">' . esc_html($slice_backup_last['error']) . '</small>';
                            }
                        }
                        ?>
                    </strong>
                </div>
            </div>

            <label class="slicebk-check slicebk-check--boxed">
                <input type="checkbox" name="enabled" value="1" <?php checked(!empty($slice_backup_sched['enabled'])); ?>>
                <span><strong><?php esc_html_e('Back up automatically', 'slice-backup'); ?></strong><small><?php esc_html_e('Runs through WP-Cron in the background. Check the System tab to make sure WP-Cron is running.', 'slice-backup'); ?></small></span>
            </label>

            <div class="slicebk-grid-3">
                <label class="slicebk-field">
                    <span class="slicebk-field__label"><?php esc_html_e('How often', 'slice-backup'); ?></span>
                    <select name="frequency" id="slicebk-sched-frequency">
                        <?php foreach ($view['frequencies'] as $slice_backup_key => $slice_backup_label) : ?>
                            <option value="<?php echo esc_attr($slice_backup_key); ?>" <?php selected($slice_backup_sched['frequency'], $slice_backup_key); ?>><?php echo esc_html($slice_backup_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="slicebk-field" id="slicebk-sched-weekday-field">
                    <span class="slicebk-field__label"><?php esc_html_e('Day', 'slice-backup'); ?></span>
                    <select name="weekday">
                        <?php
                        global $wp_locale;
                        for ($slice_backup_d = 0; $slice_backup_d < 7; $slice_backup_d++) :
                            ?>
                            <option value="<?php echo esc_attr((string) $slice_backup_d); ?>" <?php selected((int) $slice_backup_sched['weekday'], $slice_backup_d); ?>><?php echo esc_html($wp_locale->get_weekday($slice_backup_d)); ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label class="slicebk-field">
                    <span class="slicebk-field__label"><?php esc_html_e('Time (site time zone)', 'slice-backup'); ?></span>
                    <input type="time" name="time" value="<?php echo esc_attr($slice_backup_sched['time']); ?>">
                </label>
                <label class="slicebk-field">
                    <span class="slicebk-field__label"><?php esc_html_e('What to back up', 'slice-backup'); ?></span>
                    <select name="preset">
                        <?php foreach ($slice_backup_presets as $slice_backup_key => $slice_backup_preset) : ?>
                            <?php if ($slice_backup_key !== 'custom') : ?>
                                <option value="<?php echo esc_attr($slice_backup_key); ?>" <?php selected($slice_backup_sched['preset'], $slice_backup_key); ?>><?php echo esc_html($slice_backup_preset['title']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="slicebk-stack">
                <label class="slicebk-check"><input type="checkbox" name="encrypt" value="1" <?php checked(!empty($slice_backup_sched['encrypt'])); ?> <?php disabled(!$view['cryptoReady']); ?>> <span><strong><?php esc_html_e('Encrypt with the saved passphrase', 'slice-backup'); ?></strong></span></label>
                <label class="slicebk-check"><input type="checkbox" name="upload" value="1" <?php checked(!empty($slice_backup_sched['upload'])); ?>> <span><strong><?php esc_html_e('Upload to cloud storage', 'slice-backup'); ?></strong></span></label>
                <label class="slicebk-check slicebk-indent"><input type="checkbox" name="delete_local" value="1" <?php checked(!empty($slice_backup_sched['delete_local'])); ?>> <span><strong><?php esc_html_e('Then delete the server copy', 'slice-backup'); ?></strong></span></label>
            </div>

            <h3 class="slicebk-subtitle"><?php esc_html_e('Keep (grandfather-father-son rotation)', 'slice-backup'); ?></h3>
            <div class="slicebk-grid-3">
                <label class="slicebk-field"><span class="slicebk-field__label"><?php esc_html_e('Daily backups', 'slice-backup'); ?></span><input type="number" name="keep_daily" min="1" max="365" value="<?php echo esc_attr((string) $slice_backup_sched['keep_daily']); ?>"></label>
                <label class="slicebk-field"><span class="slicebk-field__label"><?php esc_html_e('Weekly backups', 'slice-backup'); ?></span><input type="number" name="keep_weekly" min="0" max="104" value="<?php echo esc_attr((string) $slice_backup_sched['keep_weekly']); ?>"></label>
                <label class="slicebk-field"><span class="slicebk-field__label"><?php esc_html_e('Monthly backups', 'slice-backup'); ?></span><input type="number" name="keep_monthly" min="0" max="120" value="<?php echo esc_attr((string) $slice_backup_sched['keep_monthly']); ?>"></label>
            </div>
            <p class="slicebk-muted slicebk-small"><?php esc_html_e('The newest backup of each of the last N days, weeks and months is kept; older scheduled backups are deleted on the server and in cloud storage. Manual backups are never touched by this rule.', 'slice-backup'); ?></p>

            <h3 class="slicebk-subtitle"><?php esc_html_e('Notifications', 'slice-backup'); ?></h3>
            <div class="slicebk-grid-3">
                <label class="slicebk-field">
                    <span class="slicebk-field__label"><?php esc_html_e('Email me', 'slice-backup'); ?></span>
                    <select name="notify_on">
                        <option value="failure" <?php selected($slice_backup_sched['notify_on'], 'failure'); ?>><?php esc_html_e('When a backup fails', 'slice-backup'); ?></option>
                        <option value="always" <?php selected($slice_backup_sched['notify_on'], 'always'); ?>><?php esc_html_e('After every backup', 'slice-backup'); ?></option>
                        <option value="never" <?php selected($slice_backup_sched['notify_on'], 'never'); ?>><?php esc_html_e('Never', 'slice-backup'); ?></option>
                    </select>
                </label>
                <label class="slicebk-field">
                    <span class="slicebk-field__label"><?php esc_html_e('Email address', 'slice-backup'); ?></span>
                    <input type="email" name="notify_email" value="<?php echo esc_attr($slice_backup_sched['notify_email']); ?>">
                </label>
                <label class="slicebk-field">
                    <span class="slicebk-field__label"><?php esc_html_e('Webhook URL (optional)', 'slice-backup'); ?></span>
                    <input type="url" name="webhook" value="<?php echo esc_attr($slice_backup_sched['webhook']); ?>" placeholder="https://hooks.slack.com/…">
                </label>
            </div>
            <p class="slicebk-muted slicebk-small"><?php esc_html_e('The webhook receives a JSON POST with event (backup.succeeded or backup.failed), site, message and details. Works with Slack, Discord, Zapier and similar services.', 'slice-backup'); ?></p>

            <div class="slicebk-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save schedule', 'slice-backup'); ?></button>
                <button type="button" class="button" id="slicebk-schedule-now" <?php disabled(empty($slice_backup_sched['enabled'])); ?>><span class="dashicons dashicons-controls-play" aria-hidden="true"></span> <?php esc_html_e('Run scheduled backup now', 'slice-backup'); ?></button>
            </div>
        </form>

        <!-- Encryption -->
        <form class="slicebk-card slicebk-settings" id="slicebk-set-encryption" data-section="encryption" novalidate>
            <h2 class="slicebk-card__title"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Encryption', 'slice-backup'); ?></h2>
            <?php if (!$view['cryptoReady']) : ?>
                <div class="slicebk-callout slicebk-callout--error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e('This server lacks the PHP OpenSSL extension, so encryption is unavailable.', 'slice-backup'); ?></p></div>
            <?php endif; ?>
            <p class="slicebk-muted"><?php esc_html_e('Bundles are encrypted with AES-256-GCM; the key is derived from your passphrase with PBKDF2-SHA256 (310,000 rounds). Any change to an encrypted bundle is detected when restoring.', 'slice-backup'); ?></p>

            <p>
                <?php if (!empty($slice_backup_s['encryption']['from_constant'])) : ?>
                    <span class="slicebk-badge slicebk-badge--ok"><?php esc_html_e('Set', 'slice-backup'); ?></span> <?php esc_html_e('Passphrase comes from the SLICE_BACKUP_ENCRYPTION_PASSPHRASE constant in wp-config.php.', 'slice-backup'); ?>
                <?php elseif (!empty($slice_backup_s['encryption']['has_passphrase'])) : ?>
                    <span class="slicebk-badge slicebk-badge--ok"><?php esc_html_e('Set', 'slice-backup'); ?></span> <?php esc_html_e('A passphrase is saved (stored encrypted with your site\'s security keys).', 'slice-backup'); ?>
                <?php else : ?>
                    <span class="slicebk-badge slicebk-badge--info"><?php esc_html_e('Not set', 'slice-backup'); ?></span> <?php esc_html_e('Save a passphrase to encrypt scheduled backups.', 'slice-backup'); ?>
                <?php endif; ?>
            </p>

            <div class="slicebk-grid-2">
                <label class="slicebk-field">
                    <span class="slicebk-field__label"><?php echo !empty($slice_backup_s['encryption']['has_passphrase']) ? esc_html__('New passphrase', 'slice-backup') : esc_html__('Passphrase', 'slice-backup'); ?></span>
                    <input type="password" name="passphrase" minlength="8" autocomplete="new-password">
                </label>
                <label class="slicebk-field">
                    <span class="slicebk-field__label"><?php esc_html_e('Repeat passphrase', 'slice-backup'); ?></span>
                    <input type="password" name="passphrase_confirm" minlength="8" autocomplete="new-password">
                </label>
            </div>
            <div class="slicebk-callout slicebk-callout--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e('Write the passphrase down somewhere safe. If it is lost, encrypted backups cannot be restored by anyone.', 'slice-backup'); ?></p></div>
            <div class="slicebk-stack">
                <label class="slicebk-check"><input type="checkbox" name="by_default" value="1" <?php checked(!empty($slice_backup_s['encryption']['by_default'])); ?>> <span><strong><?php esc_html_e('Encrypt manual backups by default', 'slice-backup'); ?></strong></span></label>
                <?php if (!empty($slice_backup_s['encryption']['has_passphrase']) && empty($slice_backup_s['encryption']['from_constant'])) : ?>
                    <label class="slicebk-check"><input type="checkbox" name="clear_passphrase" value="1"> <span><strong><?php esc_html_e('Remove the saved passphrase', 'slice-backup'); ?></strong></span></label>
                <?php endif; ?>
            </div>
            <div class="slicebk-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save encryption settings', 'slice-backup'); ?></button>
            </div>
        </form>

        <!-- General -->
        <form class="slicebk-card slicebk-settings" id="slicebk-set-general" data-section="general" novalidate>
            <h2 class="slicebk-card__title"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php esc_html_e('General', 'slice-backup'); ?></h2>

            <label class="slicebk-field">
                <span class="slicebk-field__label"><?php esc_html_e('Keep the newest manual backups', 'slice-backup'); ?></span>
                <span class="slicebk-input-suffix">
                    <input type="number" name="retention_count" min="0" max="100" step="1" value="<?php echo esc_attr((string) $view['settings']['retention_count']); ?>">
                    <span><?php esc_html_e('backups of this site', 'slice-backup'); ?></span>
                </span>
                <small class="slicebk-field__help"><?php esc_html_e('Applies to manual backups created on this site; older ones are deleted after each new backup. Scheduled backups follow the schedule\'s rotation, and bundles uploaded from other sites are never deleted. 0 keeps everything.', 'slice-backup'); ?></small>
            </label>

            <label class="slicebk-field">
                <span class="slicebk-field__label"><?php esc_html_e('Database rows per query', 'slice-backup'); ?></span>
                <input type="number" name="chunk_size" min="500" max="10000" step="100" value="<?php echo esc_attr((string) $view['settings']['chunk_size']); ?>">
                <small class="slicebk-field__help"><?php esc_html_e('Lower this if backups fail with memory errors on tables with very large rows. Default: 2500.', 'slice-backup'); ?></small>
            </label>

            <div class="slicebk-field">
                <span class="slicebk-field__label"><?php esc_html_e('Backup folder', 'slice-backup'); ?></span>
                <code class="slicebk-code"><?php echo esc_html($view['storageDir'] !== '' ? $view['storageDir'] : $view['storageError']); ?></code>
                <small class="slicebk-field__help"><?php esc_html_e('Bundles may contain passwords and personal data. Download them and store them somewhere safe; delete copies you no longer need.', 'slice-backup'); ?></small>
            </div>

            <label class="slicebk-field">
                <span class="slicebk-field__label"><?php esc_html_e('Seconds per step', 'slice-backup'); ?></span>
                <input type="number" name="step_seconds" min="5" max="120" step="1" value="<?php echo esc_attr((string) $view['settings']['step_seconds']); ?>">
                <small class="slicebk-field__help"><?php esc_html_e('Backups and restores work in steps of this length and continue automatically. Keep it below your host\'s request time limit. Default: 20.', 'slice-backup'); ?></small>
            </label>

            <div class="slicebk-field">
                <span class="slicebk-field__label"><?php esc_html_e('Command line', 'slice-backup'); ?></span>
                <code class="slicebk-code">wp slice-backup export [--encrypt] [--upload] · wp slice-backup import &lt;file&gt; · wp slice-backup list · wp slice-backup cloud-test</code>
            </div>

            <div class="slicebk-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save general settings', 'slice-backup'); ?></button>
            </div>
        </form>
        </div>
        </div>
    </section>

    <!-- SYSTEM -->
    <section class="slicebk-panel" id="slicebk-panel-system" role="tabpanel" aria-labelledby="slicebk-tab-system" hidden>
        <div class="slicebk-card">
            <div class="slicebk-toolbar">
                <div>
                    <h2 class="slicebk-card__title"><?php esc_html_e('System check', 'slice-backup'); ?></h2>
                    <p class="slicebk-muted"><?php esc_html_e('Live values from this server.', 'slice-backup'); ?></p>
                </div>
                <div class="slicebk-toolbar__actions">
                    <button type="button" class="button" id="slicebk-copy-report"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e('Copy report', 'slice-backup'); ?></button>
                </div>
            </div>
            <ul class="slicebk-checks" id="slicebk-checks">
                <?php
                $slice_backup_rows   = $view['checks'];
                $slice_backup_rows[] = array(
                    'id'     => 'probe',
                    'label'  => __('Backups hidden from the web', 'slice-backup'),
                    'value'  => __('Testing…', 'slice-backup'),
                    'status' => 'pending',
                    'help'   => '',
                );
                foreach ($slice_backup_rows as $slice_backup_row) :
                    ?>
                    <li class="slicebk-check-row is-<?php echo esc_attr($slice_backup_row['status']); ?>" data-check="<?php echo esc_attr($slice_backup_row['id']); ?>">
                        <span class="slicebk-badge slicebk-badge--<?php echo esc_attr($slice_backup_row['status']); ?>"><?php echo esc_html($slice_backup_status_labels[$slice_backup_row['status']] ?? '…'); ?></span>
                        <span class="slicebk-check-row__label"><?php echo esc_html($slice_backup_row['label']); ?></span>
                        <span class="slicebk-check-row__value"><?php echo esc_html($slice_backup_row['value']); ?></span>
                        <?php if ($slice_backup_row['help'] !== '') : ?>
                            <span class="slicebk-check-row__help"><?php echo esc_html($slice_backup_row['help']); ?></span>
                        <?php else : ?>
                            <span class="slicebk-check-row__help" hidden></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <div class="slicebk-toasts" id="slicebk-toasts" aria-live="polite" aria-atomic="false"></div>

    <dialog class="slicebk-dialog" id="slicebk-dialog" aria-labelledby="slicebk-dialog-title">
        <form method="dialog">
            <h2 id="slicebk-dialog-title"></h2>
            <p id="slicebk-dialog-message"></p>
            <div class="slicebk-dialog__actions">
                <button type="submit" class="button" value="cancel"><?php esc_html_e('Cancel', 'slice-backup'); ?></button>
                <button type="submit" class="button button-primary" value="confirm" id="slicebk-dialog-confirm"></button>
            </div>
        </form>
    </dialog>
</div>
