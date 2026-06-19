<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-local variables included from a class method, not true globals
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is always $wpdb->prefix.'wpbn_backups', never user input
if ( ! defined( 'ABSPATH' ) ) exit;
global $wpdb;
$table = $wpdb->prefix . 'wpbn_backups';

// All DB records (including deleted)
$rows = $wpdb->get_results(
    "SELECT id, filename, filesize, backup_type, status, notes, duration, created_at
     FROM {$table}
     ORDER BY id DESC LIMIT 50"
);

// ZIP files on disk not registered in DB
$db_filenames = array_column( (array) $rows, 'filename' );
$orphan_rows  = array();
if ( is_dir( WPBN_BACKUP_DIR ) ) {
    $files = glob( WPBN_BACKUP_DIR . '/*.zip' ) ?: array();
    rsort( $files );
    foreach ( $files as $fpath ) {
        $fname = basename( $fpath );
        if ( ! in_array( $fname, $db_filenames, true ) ) {
            $orphan_rows[] = (object) array(
                'id'          => null,
                'filename'    => $fname,
                'filesize'    => filesize( $fpath ),
                'backup_type' => 'manual',
                'status'      => 'unregistered',
                'notes'       => '',
                'duration'    => null,
                'created_at'  => gmdate( 'Y-m-d H:i:s', filemtime( $fpath ) ),
                '_orphan'     => true,
            );
        }
    }
}

$all_rows = array_merge( $orphan_rows, (array) $rows );

// Stats
$total      = count( $rows );
$success    = count( array_filter( (array)$rows, function($r){ return $r->status === 'complete'; } ) );
$failed     = count( array_filter( (array)$rows, function($r){ return $r->status === 'failed'; } ) );
$total_size = array_sum( array_map( function($r){
    return $r->status === 'complete' ? $r->filesize : 0;
}, (array)$rows ) );
?>
<div class="wrap wpbn-wrap">

 <div class="wpbn-page-header">
  <div class="wpbn-logo-icon"><span class="dashicons dashicons-chart-bar" style="line-height:1.2;"></span></div>
  <div>
   <h1><?php esc_html_e( 'Backup History & Statistics', 'nota-backup-restore' ); ?></h1>
   <div class="wpbn-subtitle"><?php esc_html_e( 'View backup records, charts and manage files', 'nota-backup-restore' ); ?></div>
  </div>
 </div>

 <?php if ( empty( $all_rows ) ): ?>
 <div class="card">
  <div class="card-body text-center py-5">
   <p class="text-muted mb-0"><?php esc_html_e( 'No backups found.', 'nota-backup-restore' ); ?></p>
  </div>
 </div>
 <?php else: ?>

 <!-- Stat Cards -->
 <div class="row g-3 mb-4">
  <div class="col-lg-3 col-md-6">
   <div class="card wpbn-stat-card h-100">
    <div class="card-body text-center">
     <div class="wpbn-stat-icon" style="background:#e8f4fd;margin:0 auto 8px;">
      <span class="dashicons dashicons-database" style="font-size:20px;width:20px;height:20px;line-height:36px;color:#2271b1;"></span>
     </div>
     <div class="wpbn-stat-val"><?php echo (int) $total; ?></div>
     <div class="wpbn-stat-label"><?php esc_html_e( 'Total Records', 'nota-backup-restore' ); ?></div>
    </div>
   </div>
  </div>
  <div class="col-lg-3 col-md-6">
   <div class="card wpbn-stat-card h-100">
    <div class="card-body text-center">
     <div class="wpbn-stat-icon" style="background:#edfaef;margin:0 auto 8px;">
      <span class="dashicons dashicons-yes-alt" style="font-size:20px;width:20px;height:20px;line-height:36px;color:#00a32a;"></span>
     </div>
     <div class="wpbn-stat-val" style="color:#00a32a;"><?php echo (int) $success; ?></div>
     <div class="wpbn-stat-label"><?php esc_html_e( 'Successful', 'nota-backup-restore' ); ?></div>
    </div>
   </div>
  </div>
  <div class="col-lg-3 col-md-6">
   <div class="card wpbn-stat-card h-100">
    <div class="card-body text-center">
     <div class="wpbn-stat-icon" style="background:#fce8e8;margin:0 auto 8px;">
      <span class="dashicons dashicons-dismiss" style="font-size:20px;width:20px;height:20px;line-height:36px;color:#d63638;"></span>
     </div>
     <div class="wpbn-stat-val" style="color:#d63638;"><?php echo (int) $failed; ?></div>
     <div class="wpbn-stat-label"><?php esc_html_e( 'Failed', 'nota-backup-restore' ); ?></div>
    </div>
   </div>
  </div>
  <div class="col-lg-3 col-md-6">
   <div class="card wpbn-stat-card h-100">
    <div class="card-body text-center">
     <div class="wpbn-stat-icon" style="background:#f0f6fc;margin:0 auto 8px;">
      <span class="dashicons dashicons-media-archive" style="font-size:20px;width:20px;height:20px;line-height:36px;color:#2271b1;"></span>
     </div>
     <div class="wpbn-stat-val"><?php echo esc_html( wpbn_size_format( $total_size ) ); ?></div>
     <div class="wpbn-stat-label"><?php esc_html_e( 'Total Size', 'nota-backup-restore' ); ?></div>
    </div>
   </div>
  </div>
 </div>

 <?php if ( ! empty( $orphan_rows ) ): ?>
 <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
  <span class="dashicons dashicons-warning me-2" style="color:#856404;"></span>
  <div>
   <strong><?php
   /* translators: %d: number of unregistered backup files */
   printf( esc_html( _n( '%d unregistered backup file found.', '%d unregistered backup files found.', count( $orphan_rows ), 'nota-backup-restore' ) ), count( $orphan_rows ) );
   ?></strong>
   <?php esc_html_e( 'ZIP file exists on server but has no database record. Use "Register" to add it.', 'nota-backup-restore' ); ?>
  </div>
 </div>
 <?php endif; ?>

 <!-- Calendar data -->
 <?php
 $calendar_data = array();
 foreach ( $all_rows as $r ) {
     $local_date = get_date_from_gmt( $r->created_at, 'Y-m-d' );
     if ( ! isset( $calendar_data[ $local_date ] ) ) {
         $calendar_data[ $local_date ] = array();
     }
     $calendar_data[ $local_date ][] = array(
         'id'       => $r->id,
         'filename' => $r->filename,
         'status'   => $r->status,
         'filesize' => (int) $r->filesize,
     );
 }
 ?>
 <script>var wpbnCalData=<?php echo wp_json_encode( $calendar_data ); ?>;</script>

 <!-- View Toggle -->
 <div class="wpbn-view-toggle mb-3">
  <div class="btn-group" role="group">
   <button type="button" class="btn btn-sm btn-primary wpbn-view-btn" data-wpbn-view="list">
    <span class="dashicons dashicons-list-view wpbn-vbtn-icon"></span><?php esc_html_e( 'List', 'nota-backup-restore' ); ?>
   </button>
   <button type="button" class="btn btn-sm btn-outline-primary wpbn-view-btn" data-wpbn-view="calendar">
    <span class="dashicons dashicons-calendar-alt wpbn-vbtn-icon"></span><?php esc_html_e( 'Calendar', 'nota-backup-restore' ); ?>
   </button>
  </div>
 </div>

 <!-- Calendar View -->
 <div id="wpbn-calendar-view" style="display:none;">
  <div class="wpbn-calendar card">
   <div class="card-body">
    <div class="wpbn-cal-header">
     <button type="button" id="wpbn-cal-prev">&#x2039;</button>
     <span id="wpbn-cal-title"></span>
     <button type="button" id="wpbn-cal-next">&#x203A;</button>
    </div>
    <div class="wpbn-cal-grid" id="wpbn-cal-grid"></div>
    <div id="wpbn-cal-detail" style="display:none;" class="wpbn-cal-detail"></div>
   </div>
  </div>
 </div>

 <!-- Backup List Table -->
 <div id="wpbn-list-view">
 <div class="card">
  <div class="card-header">
   <span class="dashicons dashicons-list-view me-1" style="vertical-align:text-bottom;"></span>
   <?php esc_html_e( 'Backup List', 'nota-backup-restore' ); ?>
  </div>
  <div class="card-body p-0">
   <div class="table-responsive">
   <table class="table table-hover table-striped table-sm mb-0" id="wpbn-history-table">
    <thead class="table-light">
     <tr>
      <th>#</th>
      <th><?php esc_html_e( 'Filename', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Size', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Duration', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Type', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Status', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Notes', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Cloud', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Actions', 'nota-backup-restore' ); ?></th>
      <th><?php esc_html_e( 'Date', 'nota-backup-restore' ); ?></th>
     </tr>
    </thead>
    <tbody>
    <?php foreach ( $all_rows as $r ):
     $is_orphan  = ! empty( $r->_orphan );
     $is_deleted = $r->status === 'deleted';
     $status_map = array(
         'complete'     => '<span class="badge wpbn-badge-success">'    . esc_html__( 'Completed',  'nota-backup-restore' ) . '</span>',
         'gdrive_only'  => '<span class="badge wpbn-badge-gdrive">'     . esc_html__( 'Drive Only', 'nota-backup-restore' ) . '</span>',
         'failed'       => '<span class="badge wpbn-badge-failed">'     . esc_html__( 'Failed',     'nota-backup-restore' ) . '</span>',
         'pending'      => '<span class="badge wpbn-badge-pending">'    . esc_html__( 'Pending',    'nota-backup-restore' ) . '</span>',
         'deleted'      => '<span class="badge bg-secondary">'          . esc_html__( 'Deleted',    'nota-backup-restore' ) . '</span>',
         's3_only'      => '<span class="badge" style="background:#e86f00;">&#x2601;&#xFE0F; ' . esc_html__( 'S3 only',      'nota-backup-restore' ) . '</span>',
         'wasabi_only'  => '<span class="badge" style="background:#7c3aed;">&#x2601;&#xFE0F; ' . esc_html__( 'Wasabi only',  'nota-backup-restore' ) . '</span>',
         'dropbox_only' => '<span class="badge" style="background:#0061ff;">&#x2601;&#xFE0F; ' . esc_html__( 'Dropbox only', 'nota-backup-restore' ) . '</span>',
         'cloud_only'   => '<span class="badge" style="background:#0ea5e9;">&#x2601;&#xFE0F; ' . esc_html__( 'Multi-cloud',  'nota-backup-restore' ) . '</span>',
         'unregistered' => '<span class="badge" style="background:#8c8f94;">'                  . esc_html__( 'Unregistered', 'nota-backup-restore' ) . '</span>',
     );
     $badge = $status_map[ $r->status ] ?? '<span class="badge bg-secondary">' . esc_html($r->status) . '</span>';
     $dur = '';
     if ( $r->duration ) {
         $dur = $r->duration < 60 ? $r->duration.'s' : floor($r->duration/60).'m '.($r->duration%60).'s';
     }
     $type_map = array(
         'manual'    => '<span class="badge" style="background:#e8f0fe;color:#2271b1;">' . esc_html__( 'Manual',    'nota-backup-restore' ) . '</span>',
         'scheduled' => '<span class="badge" style="background:#edfaef;color:#00a32a;">' . esc_html__( 'Automatic', 'nota-backup-restore' ) . '</span>',
         'full'      => '<span class="badge bg-light text-muted">'                       . esc_html__( 'Full',      'nota-backup-restore' ) . '</span>',
     );
     $type_badge   = $type_map[ $r->backup_type ?? '' ] ?? '<span class="text-muted small">&mdash;</span>';
     $local_exists = ! $is_orphan && ! $is_deleted && file_exists( WPBN_BACKUP_DIR . '/' . $r->filename );
    ?>
    <tr<?php echo $is_orphan ? ' class="table-warning"' : ( $is_deleted ? ' style="opacity:.55;"' : '' ); ?>>
     <td><?php echo $r->id ? (int)$r->id : '<span class="text-muted">&mdash;</span>'; ?></td>
     <td>
      <code class="small"><?php echo esc_html( $r->filename ); ?></code>
      <?php if ( $is_deleted ): ?><br><span class="text-muted" style="font-size:.75rem;"><?php esc_html_e( 'file deleted, record kept', 'nota-backup-restore' ); ?></span><?php endif; ?>
     </td>
     <td><?php echo $r->filesize ? esc_html( wpbn_size_format( $r->filesize ) ) : '&mdash;'; ?></td>
     <td class="text-muted small"><?php echo $dur ? esc_html( $dur ) : '&mdash;'; ?></td>
     <td><?php echo wp_kses_post( $type_badge ); ?></td>
     <td><?php echo wp_kses_post( $badge ); ?></td>
     <td class="text-muted small"><?php echo esc_html( $r->notes ?: '—' ); ?></td>
     <td>
      <?php
      echo '&mdash;';
      ?>
     </td>
     <td>
      <?php if ( $is_orphan ): ?>
       <button type="button" class="btn btn-sm btn-outline-primary wpbn-register-btn"
        data-filename="<?php echo esc_attr($r->filename); ?>"
        data-filesize="<?php echo (int)$r->filesize; ?>"
        data-created="<?php echo esc_attr($r->created_at); ?>">
        <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span> <?php esc_html_e( 'Register', 'nota-backup-restore' ); ?>
       </button>
      <?php elseif ( $is_deleted ): ?>
       <span class="text-muted small">&mdash;</span>
      <?php elseif ( $local_exists ): ?>
       <div class="wpbn-btn-dropdown">
        <button type="button" class="btn btn-sm btn-outline-secondary wpbn-dropdown-toggle">
         <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span> <?php esc_html_e( 'Download', 'nota-backup-restore' ); ?> <span style="font-size:.7em;opacity:.7;">▾</span>
        </button>
        <div class="wpbn-dropdown-menu">
         <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'wpbn_download_backup', 'backup_id' => (int)$r->id, 'nonce' => wp_create_nonce( 'wpbn_nonce' ) ), admin_url( 'admin-ajax.php' ) ) ); ?>" class="wpbn-dropdown-item wpbn-download-btn">📦 <?php esc_html_e( 'ZIP File', 'nota-backup-restore' ); ?></a>
         <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'wpbn_download_installer', 'backup_id' => (int)$r->id, 'nonce' => wp_create_nonce( 'wpbn_nonce' ) ), admin_url( 'admin-ajax.php' ) ) ); ?>" class="wpbn-dropdown-item wpbn-installer-btn">🔧 <?php esc_html_e( 'Installer PHP', 'nota-backup-restore' ); ?></a>
        </div>
       </div>
      <?php else: ?>
       <span class="text-muted small">&mdash;</span>
      <?php endif; ?>
     </td>
     <td class="text-nowrap small"><?php echo esc_html( get_date_from_gmt( $r->created_at, 'Y-m-d H:i' ) ); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
   </table>
   </div>
  </div>
 </div>

 </div><!-- /#wpbn-list-view -->
 <?php endif; ?>
</div>
