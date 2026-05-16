<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpbn_backups_table/$wpbn_logs_table always constructed from $wpdb->prefix, never user input; %d placeholders are in $wpbn_placeholders variable, not visible to static analysis; log queries must not be cached
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.', 'nota-backup-restore' ) );

global $wpdb;
$wpbn_backups_table = $wpdb->prefix . 'wpbn_backups';
$wpbn_logs_table    = $wpdb->prefix . 'wpbn_logs';

$wpbn_retention = (int) WPBN_Settings::get( 'log_retention_backups' );
if ( $wpbn_retention <= 0 ) $wpbn_retention = 20;

// Last N backups with at least one log entry
$wpbn_backup_ids_with_logs = $wpdb->get_col(
    "SELECT DISTINCT backup_id FROM {$wpbn_logs_table} WHERE backup_id IS NOT NULL ORDER BY backup_id DESC LIMIT 50"
);

$wpbn_backups = array();
if ( ! empty( $wpbn_backup_ids_with_logs ) ) {
    $wpbn_placeholders = implode( ',', array_fill( 0, count( $wpbn_backup_ids_with_logs ), '%d' ) );
    $wpbn_backups = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, filename, backup_type, status, created_at FROM {$wpbn_backups_table}
             WHERE id IN ({$wpbn_placeholders}) ORDER BY id DESC",
            ...$wpbn_backup_ids_with_logs
        )
    );
}

// System logs (no backup_id)
$wpbn_system_logs = WPBN_Logger::get_system_logs( 100 );

$wpbn_level_badge = array(
    'info'    => '<span class="badge bg-secondary">info</span>',
    'warning' => '<span class="badge bg-warning text-dark">warning</span>',
    'error'   => '<span class="badge bg-danger">error</span>',
);
?>
<div class="wrap wpbn-wrap">

 <div class="wpbn-page-header">
  <div class="wpbn-logo-icon"><span class="dashicons dashicons-list-view" style="line-height:1.2;"></span></div>
  <div>
   <h1><?php esc_html_e( 'Activity Logs', 'nota-backup-restore' ); ?></h1>
   <div class="wpbn-subtitle"><?php esc_html_e( 'Per-backup log entries for debugging and audit', 'nota-backup-restore' ); ?></div>
  </div>
 </div>

 <!-- Retention setting -->
 <div class="card mb-4">
  <div class="card-body d-flex align-items-center gap-3 flex-wrap">
   <label class="fw-semibold mb-0" for="wpbn-log-retention">
    <?php esc_html_e( 'Keep logs for last', 'nota-backup-restore' ); ?>
   </label>
   <input type="number" id="wpbn-log-retention" class="form-control" style="width:90px;"
          min="1" max="100" value="<?php echo esc_attr( $wpbn_retention ); ?>">
   <label class="mb-0"><?php esc_html_e( 'backups', 'nota-backup-restore' ); ?></label>
   <button id="wpbn-save-log-retention" class="btn btn-primary btn-sm">
    <?php esc_html_e( 'Save', 'nota-backup-restore' ); ?>
   </button>
   <span id="wpbn-log-retention-status" class="ms-2 text-success" style="display:none;"></span>
  </div>
 </div>

 <?php if ( empty( $wpbn_backups ) && empty( $wpbn_system_logs ) ): ?>
 <div class="card">
  <div class="card-body text-center py-5">
   <p class="text-muted mb-0"><?php esc_html_e( 'No log entries yet. Run a backup to start collecting logs.', 'nota-backup-restore' ); ?></p>
  </div>
 </div>
 <?php else: ?>

 <!-- Backup logs -->
 <?php if ( ! empty( $wpbn_backups ) ): ?>
 <div class="card mb-4">
  <div class="card-header fw-semibold"><?php esc_html_e( 'Backup Logs', 'nota-backup-restore' ); ?></div>
  <div class="table-responsive">
   <table class="table table-hover mb-0">
    <thead class="table-light">
     <tr>
      <th style="width:40px;"></th>
      <th><?php esc_html_e( 'Backup', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Type', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Status', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Created', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Log entries', 'nota-backup-restore' ); ?></th>
     </tr>
    </thead>
    <tbody>
     <?php foreach ( $wpbn_backups as $wpbn_b ):
         $wpbn_logs    = WPBN_Logger::get_by_backup( (int) $wpbn_b->id );
         $wpbn_summary = WPBN_Logger::get_summary( (int) $wpbn_b->id );
         $wpbn_row_id  = 'wpbn-logs-row-' . $wpbn_b->id;
         $wpbn_has_err = $wpbn_summary['error'] > 0;
         $wpbn_has_war = $wpbn_summary['warning'] > 0;
     ?>
     <tr class="wpbn-log-toggle-row <?php echo $wpbn_has_err ? 'table-danger' : ( $wpbn_has_war ? 'table-warning' : '' ); ?>"
         data-target="<?php echo esc_attr( $wpbn_row_id ); ?>" style="cursor:pointer;">
      <td class="text-center">
       <span class="dashicons dashicons-arrow-right-alt2 wpbn-log-arrow"></span>
      </td>
      <td class="font-monospace small"><?php echo esc_html( $wpbn_b->filename ); ?></td>
      <td><?php echo esc_html( $wpbn_b->backup_type ); ?></td>
      <td>
       <?php if ( $wpbn_b->status === 'failed' ): ?>
        <span class="badge bg-danger"><?php esc_html_e( 'Failed', 'nota-backup-restore' ); ?></span>
       <?php elseif ( $wpbn_b->status === 'complete' ): ?>
        <span class="badge bg-success"><?php esc_html_e( 'Complete', 'nota-backup-restore' ); ?></span>
       <?php else: ?>
        <span class="badge bg-secondary"><?php echo esc_html( $wpbn_b->status ); ?></span>
       <?php endif; ?>
      </td>
      <td class="text-nowrap small"><?php echo esc_html( $wpbn_b->created_at ); ?></td>
      <td>
       <span class="badge bg-secondary"><?php echo (int) $wpbn_summary['total']; ?></span>
       <?php if ( $wpbn_summary['warning'] > 0 ): ?>
        <span class="badge bg-warning text-dark"><?php echo (int) $wpbn_summary['warning']; ?> <?php esc_html_e( 'warn', 'nota-backup-restore' ); ?></span>
       <?php endif; ?>
       <?php if ( $wpbn_summary['error'] > 0 ): ?>
        <span class="badge bg-danger"><?php echo (int) $wpbn_summary['error']; ?> <?php esc_html_e( 'err', 'nota-backup-restore' ); ?></span>
       <?php endif; ?>
      </td>
     </tr>
     <tr id="<?php echo esc_attr( $wpbn_row_id ); ?>" style="display:none;">
      <td colspan="6" class="p-0">
       <div class="wpbn-log-entries bg-light px-3 py-2 border-top">
        <?php if ( empty( $wpbn_logs ) ): ?>
         <p class="text-muted small mb-0"><?php esc_html_e( 'No log entries.', 'nota-backup-restore' ); ?></p>
        <?php else: ?>
         <table class="table table-sm table-borderless mb-0 font-monospace small">
          <tbody>
           <?php foreach ( $wpbn_logs as $wpbn_entry ): ?>
           <tr>
            <td class="text-nowrap pe-3" style="color:#6b7280;width:160px;"><?php echo esc_html( $wpbn_entry->created_at ); ?></td>
            <td class="pe-3" style="width:90px;"><?php echo isset( $wpbn_level_badge[ $wpbn_entry->level ] ) ? $wpbn_level_badge[ $wpbn_entry->level ] : esc_html( $wpbn_entry->level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
            <td><?php echo esc_html( $wpbn_entry->message ); ?></td>
           </tr>
           <?php endforeach; ?>
          </tbody>
         </table>
        <?php endif; ?>
       </div>
      </td>
     </tr>
     <?php endforeach; ?>
    </tbody>
   </table>
  </div>
 </div>
 <?php endif; ?>

 <!-- System logs -->
 <?php if ( ! empty( $wpbn_system_logs ) ): ?>
 <div class="card mb-4">
  <div class="card-header fw-semibold">
   <?php esc_html_e( 'System Logs', 'nota-backup-restore' ); ?>
   <small class="text-muted ms-2"><?php esc_html_e( '(not tied to a specific backup)', 'nota-backup-restore' ); ?></small>
  </div>
  <div class="table-responsive">
   <table class="table table-sm table-hover mb-0 font-monospace small">
    <thead class="table-light">
     <tr>
      <th style="width:160px;"><?php esc_html_e( 'Time', 'nota-backup-restore' ); ?></th>
      <th style="width:90px;"><?php esc_html_e( 'Level', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Message', 'nota-backup-restore' ); ?></th>
     </tr>
    </thead>
    <tbody>
     <?php foreach ( $wpbn_system_logs as $wpbn_entry ): ?>
     <tr>
      <td class="text-nowrap" style="color:#6b7280;"><?php echo esc_html( $wpbn_entry->created_at ); ?></td>
      <td><?php echo isset( $wpbn_level_badge[ $wpbn_entry->level ] ) ? $wpbn_level_badge[ $wpbn_entry->level ] : esc_html( $wpbn_entry->level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
      <td><?php echo esc_html( $wpbn_entry->message ); ?></td>
     </tr>
     <?php endforeach; ?>
    </tbody>
   </table>
  </div>
 </div>
 <?php endif; ?>

 <?php endif; ?>
</div>
