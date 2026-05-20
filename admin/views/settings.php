<?php if ( ! defined( 'ABSPATH' ) ) exit;
$wpbn_upgrade_url = wpbn_upgrade_url();
?>
<div class="wrap wpbn-wrap">

 <div class="wpbn-page-header">
  <div class="wpbn-logo-icon"><span class="dashicons dashicons-admin-settings" style="line-height:1.2;"></span></div>
  <div>
   <h1><?php esc_html_e( 'Settings', 'nota-backup-restore' ); ?></h1>
   <div class="wpbn-subtitle"><?php esc_html_e( 'Configure backups & notifications', 'nota-backup-restore' ); ?></div>
  </div>
 </div>

 <?php
 $wpbn_pro_badge = ' <span class="wpbn-pro-badge" style="font-size:.65rem;vertical-align:middle;">PRO</span>';
 ?>

 <div class="wpbn-settings-layout">

  <!-- ── Sidebar ── -->
  <div class="wpbn-settings-sidebar nav" id="wpbn-settings-tabs">

   <div class="wpbn-sidebar-group-label"><?php esc_html_e( 'Backup', 'nota-backup-restore' ); ?></div>
   <a class="nav-link active" data-tab="autobackup" href="#tab-autobackup">
    <span class="wpbn-sidebar-icon">&#x23F1;</span> <?php esc_html_e( 'Auto Backups', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>
   <a class="nav-link" data-tab="general" href="#tab-general">
    <span class="wpbn-sidebar-icon">&#x2699;&#xFE0F;</span> <?php esc_html_e( 'General', 'nota-backup-restore' ); ?>
   </a>
   <a class="nav-link" data-tab="exclude" href="#tab-exclude">
    <span class="wpbn-sidebar-icon">&#x1F6AB;</span> <?php esc_html_e( 'Exclusions', 'nota-backup-restore' ); ?>
   </a>

   <div class="wpbn-sidebar-group-label"><?php esc_html_e( 'Cloud Storage', 'nota-backup-restore' ); ?></div>
   <a class="nav-link" data-tab="gdrive" href="#tab-gdrive">
    <span class="wpbn-sidebar-icon">&#x1F535;</span> <?php esc_html_e( 'Google Drive', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>
   <a class="nav-link" data-tab="s3" href="#tab-s3">
    <span class="wpbn-sidebar-icon">&#x1F7E0;</span> <?php esc_html_e( 'Amazon S3', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>
   <a class="nav-link" data-tab="wasabi" href="#tab-wasabi">
    <span class="wpbn-sidebar-icon">&#x1F7E3;</span> <?php esc_html_e( 'Wasabi', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>
   <a class="nav-link" data-tab="dropbox" href="#tab-dropbox">
    <span class="wpbn-sidebar-icon">&#x1F537;</span> <?php esc_html_e( 'Dropbox', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>
   <a class="nav-link" data-tab="onedrive" href="#tab-onedrive">
    <span class="wpbn-sidebar-icon">&#x1F535;</span> <?php esc_html_e( 'OneDrive', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>
   <a class="nav-link" data-tab="ftp" href="#tab-ftp">
    <span class="wpbn-sidebar-icon">&#x1F5A5;&#xFE0F;</span> <?php esc_html_e( 'FTP / SFTP', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>

   <div class="wpbn-sidebar-group-label"><?php esc_html_e( 'Advanced', 'nota-backup-restore' ); ?></div>
   <a class="nav-link" data-tab="notify" href="#tab-notify">
    <span class="wpbn-sidebar-icon">&#x1F4E7;</span> <?php esc_html_e( 'Notifications', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>
   <a class="nav-link" data-tab="emergency" href="#tab-emergency">
    <span class="wpbn-sidebar-icon">&#x1F198;</span> <?php esc_html_e( 'Emergency Recovery', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>
   <a class="nav-link" data-tab="staging" href="#tab-staging">
    <span class="wpbn-sidebar-icon">&#x26A1;</span> <?php esc_html_e( 'Staging', 'nota-backup-restore' ); ?><?php echo wp_kses_post( $wpbn_pro_badge ); ?>
   </a>

  </div><!-- /sidebar -->

  <!-- ── Main content ── -->
  <div class="wpbn-settings-main">

 <form id="wpbn-settings-form">

  <!-- AUTO BACKUPS TAB -->
  <div class="wpbn-tab-content active" id="tab-autobackup">
   <?php wpbn_render_premium_gate( __( 'Automatic Backups', 'nota-backup-restore' ), __( 'Schedule daily, weekly or monthly backups that run automatically. Never forget to back up again.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'auto-backups.png' ); ?>
  </div><!-- /tab-autobackup -->

  <!-- GOOGLE DRIVE TAB -->
  <div class="wpbn-tab-content" id="tab-gdrive">
   <?php wpbn_render_premium_gate( __( 'Google Drive Backup', 'nota-backup-restore' ), __( 'Automatically upload your backups to Google Drive after every backup. Connect multiple cloud destinations simultaneously.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'google-drive.png' ); ?>
  </div><!-- /tab-gdrive -->

  <!-- AMAZON S3 TAB -->
  <div class="wpbn-tab-content" id="tab-s3">
   <?php wpbn_render_premium_gate( __( 'Amazon S3 Backup', 'nota-backup-restore' ), __( 'Automatically upload your backups to Amazon S3. Any region, custom bucket and prefix.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'amazon-s3.png' ); ?>
  </div><!-- /tab-s3 -->

  <!-- WASABI TAB -->
  <div class="wpbn-tab-content" id="tab-wasabi">
   <?php wpbn_render_premium_gate( __( 'Wasabi Cloud Backup', 'nota-backup-restore' ), __( 'Upload backups to Wasabi S3-compatible storage. Low-cost, no egress fees.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'wasabi.png' ); ?>
  </div><!-- /tab-wasabi -->

  <!-- DROPBOX TAB -->
  <div class="wpbn-tab-content" id="tab-dropbox">
   <?php wpbn_render_premium_gate( __( 'Dropbox Backup', 'nota-backup-restore' ), __( 'Automatically upload your backups to Dropbox. OAuth2 authorization, folder picker.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'dropbox.png' ); ?>
  </div><!-- /tab-dropbox -->

  <!-- ONEDRIVE TAB -->
  <div class="wpbn-tab-content" id="tab-onedrive">
   <?php wpbn_render_premium_gate( __( 'OneDrive Backup', 'nota-backup-restore' ), __( 'Automatically upload your backups to Microsoft OneDrive. Personal and business accounts.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'onedrive.png' ); ?>
  </div><!-- /tab-onedrive -->

  <!-- FTP / SFTP TAB -->
  <div class="wpbn-tab-content" id="tab-ftp">
   <?php wpbn_render_premium_gate( __( 'FTP / SFTP Backup', 'nota-backup-restore' ), __( 'Upload your backups to any FTP or SFTP server automatically.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'ftp-sftp.png' ); ?>
  </div><!-- /tab-ftp -->

  <!-- GENERAL TAB -->
  <div class="wpbn-tab-content" id="tab-general">
   <div class="card mt-0">
    <div class="card-body">
     <h5 class="card-title mb-3"><?php esc_html_e( 'General Settings', 'nota-backup-restore' ); ?></h5>
     <div class="row g-3">
      <div class="col-12">
       <label class="form-label" for="max_backups"><?php esc_html_e( 'Maximum Backups to Keep', 'nota-backup-restore' ); ?></label>
       <input type="number" id="max_backups" name="max_backups" min="1" max="50" value="<?php echo esc_attr( $settings['max_backups'] ); ?>" class="form-control" style="max-width:100px;">
       <div class="form-text"><?php esc_html_e( 'Oldest backups are deleted automatically when this limit is exceeded.', 'nota-backup-restore' ); ?></div>
      </div>
      <div class="col-12">
       <label class="form-label" for="chunk_size_mb"><?php esc_html_e( 'ZIP Chunk Size (MB)', 'nota-backup-restore' ); ?></label>
       <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <input type="number" id="chunk_size_mb" name="chunk_size_mb" min="2" max="50" value="<?php echo esc_attr( $settings['chunk_size_mb'] ); ?>" class="form-control" style="max-width:100px;">
        <?php $wpbn_auto_val = (int)( $settings['chunk_size_mb_auto'] ?? 0 ); ?>
        <span id="wpbn-chunk-auto-badge" style="font-size:.82rem;color:#64748b;">
         <?php if ( $wpbn_auto_val > 0 ): ?>
          <?php
          /* translators: %d: auto-calculated chunk size in MB */
          printf( esc_html__( 'Auto-calculated: %d MB', 'nota-backup-restore' ), absint( $wpbn_auto_val ) );
          ?>
          <?php if ( (int)$settings['chunk_size_mb'] !== $wpbn_auto_val ): ?>
           &mdash; <a href="#" id="wpbn-chunk-reset-auto" data-val="<?php echo esc_attr( $wpbn_auto_val ); ?>"><?php esc_html_e( 'Reset to auto', 'nota-backup-restore' ); ?></a>
          <?php endif; ?>
         <?php endif; ?>
        </span>
        <button type="button" id="wpbn-chunk-recalculate" class="button button-small">&#x21BA; <?php esc_html_e( 'Recalculate', 'nota-backup-restore' ); ?></button>
       </div>
       <div class="form-text"><?php esc_html_e( 'Maximum total file size processed per ZIP request. Auto-calculated based on your server\'s available memory.', 'nota-backup-restore' ); ?></div>
      </div>
      <div class="col-12">
       <?php
       $wpbn_chunk_mb_current = max( 2, min( 50, (int)( $settings['chunk_size_mb'] ?? 5 ) ) );
       $wpbn_fpc_auto         = max( 50, min( $wpbn_chunk_mb_current * 40, 2000 ) );
       $wpbn_fpc_override     = (int)( $settings['files_per_chunk_override'] ?? 0 );
       $wpbn_fpc_override_on  = $wpbn_fpc_override > 0;
       ?>
       <label class="form-label"><?php esc_html_e( 'Files Per Chunk', 'nota-backup-restore' ); ?></label>
       <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-size:.85rem;color:#64748b;">
         <?php esc_html_e( 'Auto:', 'nota-backup-restore' ); ?> <strong id="wpbn-fpc-auto-val"><?php echo esc_html( $wpbn_fpc_auto ); ?></strong> <?php esc_html_e( 'files', 'nota-backup-restore' ); ?>
         <em style="font-size:.78rem;">(= <?php echo esc_html( $wpbn_chunk_mb_current ); ?> MB &times; 40)</em>
        </span>
        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;cursor:pointer;margin:0;">
         <input type="checkbox" id="wpbn-fpc-override-toggle" <?php checked( $wpbn_fpc_override_on ); ?>>
         <?php esc_html_e( 'Override', 'nota-backup-restore' ); ?>
        </label>
        <input type="number" id="files_per_chunk_override" name="files_per_chunk_override"
               min="10" max="2000"
               value="<?php echo esc_attr( $wpbn_fpc_override > 0 ? $wpbn_fpc_override : $wpbn_fpc_auto ); ?>"
               class="form-control" style="max-width:100px;<?php echo $wpbn_fpc_override_on ? '' : 'display:none;'; ?>">
       </div>
       <div class="form-text"><?php esc_html_e( 'Auto-calculated from chunk size. Override only if you need a custom value.', 'nota-backup-restore' ); ?></div>
      </div>
      <div class="col-12">
       <label class="form-label"><?php esc_html_e( 'Backup Encryption', 'nota-backup-restore' ); ?></label>
       <div class="form-check">
        <input class="form-check-input" type="checkbox" id="encryption_enabled" name="encryption_enabled" value="1" <?php checked( $settings['encryption_enabled'] ?? '0', '1' ); ?>>
        <label class="form-check-label" for="encryption_enabled"><?php esc_html_e( 'Enable AES-256 encryption for all backups', 'nota-backup-restore' ); ?></label>
       </div>
       <div class="form-text"><?php
        /* translators: <code> tags are intentional HTML */
        echo wp_kses( __( 'When enabled, <code>database.sql</code> inside each backup ZIP is encrypted. The password is stored securely using your WordPress secret keys.', 'nota-backup-restore' ), array( 'code' => array() ) );
       ?></div>
       <div id="wpbn-enc-pass-row" style="margin-top:10px;<?php echo ( $settings['encryption_enabled'] ?? '0' ) !== '1' ? 'display:none;' : ''; ?>">
        <label class="form-label" for="encryption_password"><?php esc_html_e( 'Encryption Password', 'nota-backup-restore' ); ?></label>
        <input type="password" id="encryption_password" name="encryption_password" class="form-control" placeholder="<?php echo ( $settings['encryption_password'] ?? '' ) !== '' ? esc_attr__( '(saved — enter new to change)', 'nota-backup-restore' ) : esc_attr__( 'Enter a strong password', 'nota-backup-restore' ); ?>" style="max-width:320px;" autocomplete="new-password">
        <div class="form-text text-danger">&#x26A0;&#xFE0F; <?php esc_html_e( 'If you lose this password, encrypted backups cannot be restored.', 'nota-backup-restore' ); ?></div>
        <?php if ( ( $settings['encryption_password'] ?? '' ) !== '' ) : ?>
        <button type="button" id="wpbn-remove-encryption" class="button button-secondary" style="margin-top:8px;color:#b32d2e;"><?php esc_html_e( 'Remove Encryption', 'nota-backup-restore' ); ?></button>
        <?php endif; ?>
       </div>
      </div>
     </div>
    </div>
   </div>
  </div><!-- /tab-general -->


  <!-- NOTIFICATIONS TAB -->
  <div class="wpbn-tab-content" id="tab-notify">
   <?php wpbn_render_premium_gate( __( 'Email Notifications', 'nota-backup-restore' ), __( 'Get notified by email on backup success or failure. Includes error details for quick diagnosis.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'notifications.png' ); ?>
  </div><!-- /tab-notify -->

  <!-- EXCLUSIONS TAB -->
  <div class="wpbn-tab-content" id="tab-exclude">
   <div class="card mt-0">
    <div class="card-body">
     <h5 class="card-title mb-1"><?php esc_html_e( 'Excluded Folders', 'nota-backup-restore' ); ?></h5>
     <p class="text-muted mb-3" style="font-size:.9rem;"><?php esc_html_e( 'Select folders to exclude from backups. The backup folder is already excluded automatically.', 'nota-backup-restore' ); ?></p>

     <div class="mb-4">
      <p class="fw-semibold mb-2" style="font-size:.9rem;"><?php esc_html_e( 'Cache directories', 'nota-backup-restore' ); ?></p>
      <?php
      $wpbn_enabled_presets = (array) $settings['excluded_cache_presets'];
      foreach ( WPBN_Backup::known_cache_dirs() as $wpbn_cache_key => $wpbn_cache_label ) :
      ?>
      <div class="form-check mb-1">
       <input class="form-check-input" type="checkbox" name="excluded_cache_presets[]"
              id="cache_preset_<?php echo esc_attr( str_replace( '/', '_', $wpbn_cache_key ) ); ?>"
              value="<?php echo esc_attr( $wpbn_cache_key ); ?>"
              <?php checked( in_array( $wpbn_cache_key, $wpbn_enabled_presets, true ) ); ?>>
       <label class="form-check-label" for="cache_preset_<?php echo esc_attr( str_replace( '/', '_', $wpbn_cache_key ) ); ?>" style="font-size:.88rem;">
        <?php echo esc_html( $wpbn_cache_label ); ?> &mdash; <code>wp-content/<?php echo esc_html( $wpbn_cache_key ); ?></code>
       </label>
      </div>
      <?php endforeach; ?>
     </div>

     <div id="wpbn-exclude-wrap">
      <!-- Selected paths -->
      <div id="wpbn-selected-paths-box" style="margin-bottom:16px;min-height:38px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
       <span id="wpbn-no-selection" style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'No folders selected yet.', 'nota-backup-restore' ); ?></span>
      </div>

      <!-- Tree -->
      <div id="wpbn-dir-tree" style="border:1px solid #e2e8f0;border-radius:8px;background:#fff;max-height:420px;overflow-y:auto;font-size:.88rem;">
       <div id="wpbn-tree-loading" style="padding:20px;text-align:center;color:#94a3b8;">&#x23F3; <?php esc_html_e( 'Loading…', 'nota-backup-restore' ); ?></div>
      </div>

      <!-- Hidden input -->
      <input type="hidden" id="exclude_paths" name="exclude_paths" value="<?php echo esc_attr( implode( "\n", (array) $settings['exclude_paths'] ) ); ?>">
     </div>
    </div>
   </div>
  </div><!-- /tab-exclude -->

  <!-- EMERGENCY RECOVERY TAB -->
  <div class="wpbn-tab-content" id="tab-emergency">
   <?php wpbn_render_premium_gate( __( 'Emergency Recovery', 'nota-backup-restore' ), __( 'Access and restore your backups even when WordPress is completely broken — directly from your browser, no admin panel needed.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'emergency-recovery.png' ); ?>
  </div><!-- /tab-emergency -->

  <!-- STAGING TAB -->
  <div class="wpbn-tab-content" id="tab-staging">
   <?php wpbn_render_premium_gate( __( 'Staging', 'nota-backup-restore' ), __( 'Create a full staging copy of your live site with one click. Test plugins, themes or major changes safely — then push to live when ready.', 'nota-backup-restore' ), $wpbn_upgrade_url, 'staging.png' ); ?>
  </div><!-- /tab-staging -->

  <div class="mt-3 mb-4">
   <button type="button" id="wpbn-save-settings" class="btn btn-primary btn-lg"><?php esc_html_e( 'Save Settings', 'nota-backup-restore' ); ?></button>
   <span id="wpbn-save-msg" class="wpbn-save-msg"></span>
  </div>

 </form>

  </div><!-- /wpbn-settings-main -->
 </div><!-- /wpbn-settings-layout -->
</div>
