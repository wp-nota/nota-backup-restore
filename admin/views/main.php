<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-local variables included from a class method, not true globals
if ( ! defined( 'ABSPATH' ) ) exit;
$wpbn_upgrade_url = wpbn_upgrade_url();
?>
<div class="wrap wpbn-wrap">

 <div class="wpbn-page-header">
  <div class="wpbn-logo-icon"><span class="dashicons dashicons-backup" style="line-height:1.2;"></span></div>
  <div>
   <h1>Nota Backup &amp; Restore</h1>
   <div class="wpbn-subtitle"><?php esc_html_e( 'Full site backup plugin', 'nota-backup-restore' ); ?></div>
  </div>
 </div>

 <div class="notice notice-info wpbn-upgrade-notice" style="display:flex;align-items:center;gap:16px;padding:12px 16px;margin-bottom:16px;border-radius:6px;">
  <span style="font-size:1.5rem;">&#x1F513;</span>
  <div style="flex:1;">
   <strong>Nota Backup &amp; Restore — <?php esc_html_e( 'Free', 'nota-backup-restore' ); ?></strong>
   <p style="margin:2px 0 0;font-size:.87rem;color:#64748b;"><?php esc_html_e( 'Cloud storage, restore wizard, scheduled backups & more require the premium version.', 'nota-backup-restore' ); ?></p>
  </div>
  <a href="<?php echo esc_url( $wpbn_upgrade_url ); ?>" class="button button-primary" target="_blank" rel="noopener" style="white-space:nowrap;"><?php esc_html_e( 'Upgrade to Pro', 'nota-backup-restore' ); ?> &#x2192;</a>
 </div>

 <!-- Create New Backup -->
 <div class="card mb-4">
    <div class="card-header">
     <span class="dashicons dashicons-backup" style="font-size:1rem;vertical-align:text-bottom;margin-right:4px;color:#2271b1;"></span>
     <?php esc_html_e( 'Create New Backup', 'nota-backup-restore' ); ?>
    </div>
    <div class="card-body">

     <div id="wpbn-site-changes-bar" style="display:none;margin-bottom:12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:9px 14px;font-size:.85rem;color:#0c4a6e;display:flex;align-items:flex-start;gap:10px;">
      <span style="font-size:1rem;flex-shrink:0;">📊</span>
      <div id="wpbn-site-changes-content" style="flex:1;"></div>
      <button type="button" id="wpbn-site-changes-dismiss" style="background:none;border:none;cursor:pointer;color:#0c4a6e;font-size:.9rem;line-height:1;padding:0;flex-shrink:0;" title="<?php esc_attr_e( 'Dismiss', 'nota-backup-restore' ); ?>">✕</button>
     </div>

     <div class="wpbn-size-bar" id="wpbn-size-estimate-bar">
      <span class="size-label">&#x1F4E6; <?php esc_html_e( 'Estimated size:', 'nota-backup-restore' ); ?></span>
      <span class="size-value" id="wpbn-size-est-val"><?php esc_html_e( 'Calculating…', 'nota-backup-restore' ); ?></span>
      <span class="size-detail" id="wpbn-size-est-detail"></span>
      <button type="button" id="wpbn-size-refresh" class="size-refresh" title="<?php esc_attr_e( 'Refresh', 'nota-backup-restore' ); ?>">&#x21BA;</button>
     </div>

     <div id="wpbn-db-health-bar" style="display:none;margin-bottom:14px;"></div>

     <!-- Cloud storage upsell row -->
     <div class="wpbn-premium-row" style="margin-bottom:16px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;display:flex;align-items:center;gap:12px;">
      <span style="font-size:1.1rem;">&#x2601;&#xFE0F;</span>
      <div style="flex:1;font-size:.88rem;color:#374151;">
       <strong><?php esc_html_e( 'Cloud Storage', 'nota-backup-restore' ); ?></strong> &mdash; <?php esc_html_e( 'Upload backups to Google Drive, S3, Wasabi, Dropbox, OneDrive or FTP.', 'nota-backup-restore' ); ?>
      </div>
      <a href="<?php echo esc_url( $wpbn_upgrade_url ); ?>" class="button" target="_blank" rel="noopener" style="white-space:nowrap;"><?php esc_html_e( 'Get Pro', 'nota-backup-restore' ); ?> &#x2192;</a>
     </div>

     <?php $exclude_paths = array_filter( (array) ( $settings['exclude_paths'] ?? array() ) ); ?>
     <input type="hidden" id="wpbn-backup-type" value="full">
     <div class="mb-1">
      <label class="form-label"><?php esc_html_e( 'Backup Type', 'nota-backup-restore' ); ?></label>
      <div class="wpbn-btype-cards">
       <button type="button" class="wpbn-btype-card wpbn-btype-card--active" data-btype="full">
        <span class="wpbn-btype-icon">🗄️</span>
        <span class="wpbn-btype-title"><?php esc_html_e( 'Full', 'nota-backup-restore' ); ?></span>
        <span class="wpbn-btype-desc"><?php esc_html_e( 'Files + Database', 'nota-backup-restore' ); ?></span>
       </button>
       <button type="button" class="wpbn-btype-card" data-btype="db_only">
        <span class="wpbn-btype-icon">🗃️</span>
        <span class="wpbn-btype-title"><?php esc_html_e( 'Database Only', 'nota-backup-restore' ); ?></span>
        <span class="wpbn-btype-desc"><?php esc_html_e( 'SQL tables only', 'nota-backup-restore' ); ?></span>
       </button>
       <button type="button" class="wpbn-btype-card" data-btype="files_only">
        <span class="wpbn-btype-icon">📂</span>
        <span class="wpbn-btype-title"><?php esc_html_e( 'Files Only', 'nota-backup-restore' ); ?></span>
        <span class="wpbn-btype-desc"><?php esc_html_e( 'No database', 'nota-backup-restore' ); ?></span>
       </button>
      </div>
      <!-- Table picker — shown only when Database Only is selected -->
      <div id="wpbn-table-picker" style="display:none;margin-top:10px;">
       <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;gap:8px;">
        <span class="form-text" style="margin:0;" id="wpbn-table-picker-hint"><?php esc_html_e( 'All tables will be backed up.', 'nota-backup-restore' ); ?></span>
        <div style="display:flex;gap:4px;flex-shrink:0;">
         <button type="button" id="wpbn-table-picker-all" class="btn btn-outline-secondary btn-sm" style="font-size:.75rem;padding:2px 8px;"><?php esc_html_e( 'Select All', 'nota-backup-restore' ); ?></button>
         <button type="button" id="wpbn-table-picker-none" class="btn btn-outline-secondary btn-sm" style="font-size:.75rem;padding:2px 8px;"><?php esc_html_e( 'Deselect All', 'nota-backup-restore' ); ?></button>
        </div>
       </div>
       <div id="wpbn-table-picker-list" style="max-height:260px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;background:#fafafa;"></div>
      </div>

      <!-- File picker — shown only when Files Only is selected -->
      <div id="wpbn-file-picker" style="display:none;margin-top:10px;">
       <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;gap:8px;">
        <span class="form-text" style="margin:0;" id="wpbn-picker-hint"><?php esc_html_e( 'No items selected — all files will be backed up and exclusion rules apply.', 'nota-backup-restore' ); ?></span>
        <div style="display:flex;gap:4px;flex-shrink:0;">
         <button type="button" id="wpbn-picker-select-all" class="btn btn-outline-secondary btn-sm" style="font-size:.75rem;padding:2px 8px;"><?php esc_html_e( 'Select All', 'nota-backup-restore' ); ?></button>
         <button type="button" id="wpbn-picker-select-none" class="btn btn-outline-secondary btn-sm" style="font-size:.75rem;padding:2px 8px;"><?php esc_html_e( 'Deselect All', 'nota-backup-restore' ); ?></button>
        </div>
       </div>
       <div id="wpbn-file-picker-tree" style="max-height:260px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;background:#fafafa;">
        <div style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'Loading…', 'nota-backup-restore' ); ?></div>
       </div>
      </div>

      <div id="wpbn-exclusion-notice" class="form-text mt-1" style="display:none;">
       <?php if ( ! empty( $exclude_paths ) ): ?>
       📋 <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-backup-nota-settings&tab=exclude' ) ); ?>"><?php esc_html_e( 'Exclusion rules', 'nota-backup-restore' ); ?></a> <?php
       /* translators: %d: number of excluded paths */
       printf( esc_html( _n( 'will be applied (%d path excluded).', 'will be applied (%d paths excluded).', count( $exclude_paths ), 'nota-backup-restore' ) ), count( $exclude_paths ) ); ?>
       <?php else: ?>
       📋 <?php esc_html_e( 'No exclusion rules defined.', 'nota-backup-restore' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-backup-nota-settings&tab=exclude' ) ); ?>"><?php esc_html_e( 'Add exclusions', 'nota-backup-restore' ); ?></a> <?php esc_html_e( 'in Settings if needed.', 'nota-backup-restore' ); ?>
       <?php endif; ?>
      </div>
     </div>

     <div class="mb-3">
      <label class="form-label"><?php esc_html_e( 'Notes (optional)', 'nota-backup-restore' ); ?></label>
      <input type="text" id="wpbn-notes" class="form-control" placeholder="<?php esc_attr_e( 'e.g. Before plugin update', 'nota-backup-restore' ); ?>">
     </div>

     <button class="btn btn-primary btn-hero" id="wpbn-start-backup">
      <span class="dashicons dashicons-backup" style="font-size:1.1rem;line-height:1.4;"></span> <?php esc_html_e( 'Start Backup', 'nota-backup-restore' ); ?>
     </button>

     <div id="wpbn-progress" style="display:none;margin-top:20px;">
      <div class="progress"><div class="progress-bar" id="wpbn-progress-inner" style="width:0%"></div></div>
      <p id="wpbn-progress-msg" class="wpbn-progress-msg"><?php esc_html_e( 'Preparing backup…', 'nota-backup-restore' ); ?></p>
     </div>
    </div>
 </div>

 <!-- Backup List -->
 <div class="card mb-4">
  <div class="card-header d-flex align-items-center gap-2">
   <span><?php esc_html_e( 'Backups', 'nota-backup-restore' ); ?></span>
   <button class="btn btn-outline-secondary btn-sm ms-auto" id="wpbn-refresh-list">&#x21BB; <?php esc_html_e( 'Refresh', 'nota-backup-restore' ); ?></button>
   <button class="btn btn-outline-danger btn-sm" id="wpbn-cleanup-orphans" title="<?php esc_attr_e( 'Clean up leftover temporary files and folders', 'nota-backup-restore' ); ?>">&#x1F9F9; <?php esc_html_e( 'Clean Temp', 'nota-backup-restore' ); ?></button>
   <span id="wpbn-cleanup-msg" style="font-size:.82rem;color:#64748b;"></span>
  </div>
  <div class="card-body p-0">
   <div id="wpbn-backup-list">
    <?php if ( empty( $backups ) ): ?>
    <p class="wpbn-empty py-4"><?php esc_html_e( 'No backups yet. Create your first backup above.', 'nota-backup-restore' ); ?></p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table table-hover mb-0">
     <thead>
      <tr>
       <th><?php esc_html_e( 'Filename', 'nota-backup-restore' ); ?></th>
       <th><?php esc_html_e( 'Size', 'nota-backup-restore' ); ?></th>
       <th><?php esc_html_e( 'Status', 'nota-backup-restore' ); ?></th>
       <th><?php esc_html_e( 'Created', 'nota-backup-restore' ); ?></th>
       <th><?php esc_html_e( 'Actions', 'nota-backup-restore' ); ?></th>
      </tr>
     </thead>
     <tbody>
     <?php
     $status_labels = array(
         'complete' => '<span class="badge wpbn-badge-success">&#x2705; ' . esc_html__( 'Complete', 'nota-backup-restore' ) . '</span>',
         'failed'   => '<span class="badge wpbn-badge-failed">&#x274C; '   . esc_html__( 'Failed',   'nota-backup-restore' ) . '</span>',
         'pending'  => '<span class="badge wpbn-badge-pending">&#x23F3; '  . esc_html__( 'Pending',  'nota-backup-restore' ) . '</span>',
     );
     foreach ( $backups as $b ):
         $status_badge = $status_labels[ $b->status ] ?? '<span class="badge bg-secondary">' . esc_html( $b->status ) . '</span>';
         $local_exists = file_exists( WPBN_BACKUP_DIR . '/' . $b->filename );
     ?>
     <tr data-id="<?php echo esc_attr( $b->id ); ?>">
      <td><span class="dashicons dashicons-archive" style="font-size:.9rem;color:#64748b;vertical-align:text-bottom;"></span> <?php echo esc_html( $b->filename ); ?></td>
      <td><?php echo esc_html( wpbn_size_format( $b->filesize ) ); ?></td>
      <td>
       <?php echo wp_kses_post( $status_badge ); ?>
       <?php if ( $b->status === 'failed' && ! empty( $b->error_msg ) ): ?>
       <br><small class="text-muted" title="<?php echo esc_attr( $b->error_msg ); ?>" style="cursor:help;">&#x2139;&#xFE0F; <?php echo esc_html( mb_strimwidth( $b->error_msg, 0, 60, '&#x2026;' ) ); ?></small>
       <?php endif; ?>
      </td>
      <td style="white-space:nowrap;"><?php echo esc_html( date_i18n( 'Y-m-d H:i', strtotime( $b->created_at ) ) ); ?></td>
      <td style="text-align:right;">
       <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
       <?php if ( $local_exists ): ?>
       <div class="wpbn-btn-dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm wpbn-dropdown-toggle">⬇ <?php esc_html_e( 'Download', 'nota-backup-restore' ); ?> <span style="font-size:.7em;opacity:.7;">▾</span></button>
        <div class="wpbn-dropdown-menu">
         <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wpbn_download_backup&backup_id=' . $b->id . '&nonce=' . wp_create_nonce( 'wpbn_nonce' ) ) ); ?>" class="wpbn-dropdown-item wpbn-download-btn">📦 <?php esc_html_e( 'ZIP File', 'nota-backup-restore' ); ?></a>
         <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wpbn_download_installer&backup_id=' . $b->id . '&nonce=' . wp_create_nonce( 'wpbn_nonce' ) ) ); ?>" class="wpbn-dropdown-item wpbn-installer-btn">🔧 <?php esc_html_e( 'Installer PHP', 'nota-backup-restore' ); ?></a>
        </div>
       </div>
       <div class="wpbn-btn-dropdown">
        <button type="button" class="btn btn-outline-warning btn-sm wpbn-dropdown-toggle">↩ <?php esc_html_e( 'Actions', 'nota-backup-restore' ); ?> <span style="font-size:.7em;opacity:.7;">▾</span></button>
        <div class="wpbn-dropdown-menu">
         <a href="<?php echo esc_url( $wpbn_upgrade_url ); ?>" target="_blank" rel="noopener" class="wpbn-dropdown-item" title="<?php esc_attr_e( 'Restore requires the premium version', 'nota-backup-restore' ); ?>">↩ <?php esc_html_e( 'Restore', 'nota-backup-restore' ); ?> <span class="wpbn-pro-badge">PRO</span></a>
        </div>
       </div>
       <?php else: ?>
       <span class="wpbn-muted">&#x2014;</span>
       <?php endif; ?>
       <button class="btn btn-outline-danger btn-sm wpbn-delete-backup" data-id="<?php echo esc_attr( $b->id ); ?>">&#x1F5D1;</button>
       </div>
      </td>
     </tr>
     <?php endforeach; ?>
     </tbody>
    </table>
    </div>
    <?php endif; ?>
   </div>
  </div>
 </div>

 <!-- Site Information -->
 <div class="card">
  <div class="card-header" style="background:transparent;border-bottom:1px solid rgba(0,0,0,.06);">
   <span class="dashicons dashicons-info" style="font-size:1rem;vertical-align:text-bottom;margin-right:4px;color:#2271b1;"></span>
   <?php esc_html_e( 'Site Information', 'nota-backup-restore' ); ?>
  </div>
  <div class="card-body">
   <table class="wpbn-info-table">
    <tr><td><?php esc_html_e( 'Plugin Version', 'nota-backup-restore' ); ?></td><td><?php echo esc_html( WPBN_VERSION ); ?></td></tr>
    <tr><td><?php esc_html_e( 'Site URL', 'nota-backup-restore' ); ?></td><td><?php echo esc_html( get_site_url() ); ?></td></tr>
    <tr><td><?php esc_html_e( 'WP Version', 'nota-backup-restore' ); ?></td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
    <tr><td><?php esc_html_e( 'PHP Version', 'nota-backup-restore' ); ?></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
    <tr><td><?php esc_html_e( 'Backup Dir', 'nota-backup-restore' ); ?></td><td><code style="font-size:.8rem;"><?php echo esc_html( WPBN_BACKUP_DIR ); ?></code></td></tr>
    <tr><td><?php esc_html_e( 'Max Backups', 'nota-backup-restore' ); ?></td><td><?php echo esc_html( $settings['max_backups'] ?? '5' ); ?></td></tr>
    <tr><td><?php esc_html_e( 'ZIP Method', 'nota-backup-restore' ); ?></td><td><?php
        echo extension_loaded( 'zip' )
            ? '<span class="text-success">&#x2705; ' . esc_html__( 'ZipArchive (PHP)', 'nota-backup-restore' ) . '</span>'
            : '<span class="text-warning">&#x26A0;&#xFE0F; ' . esc_html__( 'ZipArchive not available', 'nota-backup-restore' ) . '</span>';
    ?></td></tr>
    <tr><td><?php esc_html_e( 'Exclusions', 'nota-backup-restore' ); ?></td><td><?php if ( ! empty( $exclude_paths ) ): ?>
     <details style="cursor:pointer;">
      <summary style="color:#2271b1;"><?php
      /* translators: %d: number of excluded paths */
      printf( esc_html( _n( '%d path excluded', '%d paths excluded', count( $exclude_paths ), 'nota-backup-restore' ) ), count( $exclude_paths ) ); ?></summary>
      <ul style="margin:6px 0 0 14px;padding:0;font-size:.8rem;line-height:1.8;">
       <?php foreach ( $exclude_paths as $ep ): ?>
       <li><code style="font-size:.78rem;"><?php echo esc_html( $ep ); ?></code></li>
       <?php endforeach; ?>
      </ul>
     </details>
    <?php else: ?>
     <span class="text-muted"><?php esc_html_e( 'None', 'nota-backup-restore' ); ?> &#x2014; <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-backup-nota-settings&tab=exclude' ) ); ?>"><?php esc_html_e( 'add rules', 'nota-backup-restore' ); ?></a></span>
    <?php endif; ?></td></tr>
    <tr><td><?php esc_html_e( 'Auto Backup', 'nota-backup-restore' ); ?></td><td><span class="text-muted"><?php esc_html_e( 'Disabled', 'nota-backup-restore' ); ?> &#x2014; <a href="<?php echo esc_url( $wpbn_upgrade_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Pro', 'nota-backup-restore' ); ?></a></span></td></tr>
   </table>
  </div>
 </div>

</div><!-- /wrap -->
