<?php
/**
 * Mazyoud Image Align — admin UI, AJAX endpoints, bulk actions.
 */

if ( ! class_exists( 'Maz_IA_Admin' ) ) :

final class Maz_IA_Admin {

	const NONCE = 'maz_ia_nonce';
	const EXCLUDE_TYPE = '__exclude'; // dropdown value that marks "Not a hanger photo"

	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 10, 2 );
		add_filter( 'bulk_actions-upload',        [ __CLASS__, 'bulk_actions' ] );
		add_filter( 'handle_bulk_actions-upload', [ __CLASS__, 'handle_bulk' ], 10, 3 );
		add_action( 'admin_notices',              [ __CLASS__, 'notices' ] );
		add_filter( 'manage_media_columns',       [ __CLASS__, 'column' ] );
		add_action( 'manage_media_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
		foreach ( [ 'align', 'preview', 'revert', 'exclude', 'job' ] as $a ) add_action( 'wp_ajax_maz_ia_' . $a, [ __CLASS__, 'ajax_' . $a ] );
	}

	/* ------------------------------------------------------------ assets */

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$is_product = $screen && $screen->post_type === 'product' && in_array( $hook, [ 'post.php', 'post-new.php' ], true );
		if ( ! in_array( $hook, [ 'upload.php', 'post.php', 'post-new.php', 'media.php' ], true ) && ! $is_product ) return;
		if ( ! current_user_can( 'upload_files' ) ) return;
		$o = Maz_IA_Settings::opts();
		wp_enqueue_style( 'maz-ia', plugins_url( 'assets/align.css', MAZ_IA_FILE ), [], MAZ_IA_VERSION );
		wp_enqueue_script( 'maz-ia', plugins_url( 'assets/align.js', MAZ_IA_FILE ), [ 'jquery' ], MAZ_IA_VERSION, true );
		wp_localize_script( 'maz-ia', 'MazIA', [
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'isProduct' => $is_product ? 1 : 0,
			'isUpload'  => $hook === 'upload.php' ? 1 : 0,
			'preview'   => (int) $o['preview'],
			'types'     => Maz_IA_Settings::types(),
			'excludeValue' => self::EXCLUDE_TYPE,
			'flagLabels'=> Maz_IA_Engine::flag_labels(),
			'states'    => $is_product ? self::states_for_product() : (object) [],
			'jobs'      => $hook === 'upload.php' ? Maz_IA_Queue::active_jobs() : [],
		] );
	}

	/** Per-attachment state for every image of the product being edited — one meta query. */
	private static function states_for_product() {
		$pid = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $pid ) return (object) [];
		$ids = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) ) );
		$ids[] = (int) get_post_thumbnail_id( $pid );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) return (object) [];
		update_meta_cache( 'post', $ids );
		$out = [];
		foreach ( $ids as $i ) $out[ $i ] = Maz_IA_Files::state( $i );
		return $out;
	}

	/* ------------------------------------------------------------ UI pieces */

	public static function type_select_html( $state, $class = 'maz-ia-type' ) {
		$selected = $state['excluded'] ? self::EXCLUDE_TYPE : $state['type'];
		$h = '<select class="' . esc_attr( $class ) . '" aria-label="Garment type">';
		foreach ( Maz_IA_Settings::types() as $k => $t ) $h .= sprintf( '<option value="%s"%s>%s (%d%%)</option>', esc_attr( $k ), selected( $selected, $k, false ), esc_html( $t['label'] ), (int) $t['fill'] );
		$h .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( self::EXCLUDE_TYPE ), selected( $selected, self::EXCLUDE_TYPE, false ), esc_html( '✕ Not a hanger photo (never align)' ) );
		return $h . '</select>';
	}

	/** Buttons + badges for one attachment. $compact = list column. */
	public static function controls_html( $id, $compact = false ) {
		$s = Maz_IA_Files::state( $id );
		$btn_label = $s['excluded'] ? 'Excluded' : ( $s['done'] ? ( $compact ? '✓ redo' : '✓ Aligned — redo' ) : 'Align' );
		$h  = '<span class="maz-ia-wrap" data-id="' . (int) $id . '">' . self::type_select_html( $s ) . ( $compact ? '<br>' : ' ' );
		$h .= sprintf( '<button type="button" class="button %s maz-ia-btn%s" data-id="%d">%s</button>', $compact ? 'button-small' : '', $s['done'] ? ' maz-ia-done' : '', (int) $id, esc_html( $btn_label ) );
		$h .= sprintf( ' <button type="button" class="button button-small button-link maz-ia-revert" data-id="%d"%s>Revert</button>', (int) $id, $s['backup'] ? '' : ' hidden' );
		$h .= '<span class="maz-ia-status"></span>';
		$h .= '<span class="maz-ia-flags">' . self::flags_html( $s['flags'] ) . '</span>';
		if ( ! $compact ) {
			$hist = Maz_IA_Files::history( $id );
			if ( $hist ) {
				$h .= '<details class="maz-ia-history"><summary>History (' . count( $hist ) . ')</summary><ul>';
				foreach ( array_slice( $hist, 0, 8 ) as $e ) {
					$u = ! empty( $e['user'] ) ? get_userdata( $e['user'] ) : null;
					$line = date_i18n( 'Y-m-d H:i', (int) $e['time'] ) . ' · ' . $e['action'];
					if ( ! empty( $e['type'] ) )     $line .= ' · ' . $e['type'];
					if ( ! empty( $e['engine'] ) )   $line .= ' · ' . $e['engine'] . ' ' . (int) ( $e['ms'] ?? 0 ) . ' ms';
					if ( ! empty( $e['settings'] ) ) $line .= ' · settings ' . $e['settings'];
					if ( ! empty( $e['ball'] ) )     $line .= ' · ball ' . $e['ball'];
					if ( ! empty( $e['flags'] ) )    $line .= ' · ⚠ ' . implode( ', ', (array) $e['flags'] );
					if ( $u )                        $line .= ' · ' . $u->display_name;
					$h .= '<li>' . esc_html( $line ) . '</li>';
				}
				$h .= '</ul></details>';
			}
		}
		return $h . '</span>';
	}

	public static function flags_html( $flags ) {
		if ( ! $flags ) return '';
		$labels = Maz_IA_Engine::flag_labels();
		$title = implode( "\n", array_map( function ( $f ) use ( $labels ) { return $labels[ $f ] ?? $f; }, (array) $flags ) );
		return sprintf( '<span class="maz-ia-flag" title="%s">⚠ review</span>', esc_attr( $title ) );
	}

	public static function attachment_field( $fields, $post ) {
		if ( strpos( (string) $post->post_mime_type, 'image/' ) !== 0 || ! Maz_IA_Files::user_can( $post->ID ) ) return $fields;
		$fields['maz_align'] = [ 'label' => 'Align', 'input' => 'html', 'html' => self::controls_html( $post->ID ) ];
		return $fields;
	}

	public static function column( $cols ) { $cols['maz_ia'] = 'Align'; return $cols; }

	public static function column_content( $col, $id ) {
		if ( $col !== 'maz_ia' || ! wp_attachment_is_image( $id ) || ! Maz_IA_Files::user_can( $id ) ) return;
		echo self::controls_html( $id, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
	}

	/* ------------------------------------------------------------ bulk */

	public static function bulk_actions( $a ) {
		foreach ( Maz_IA_Settings::types() as $k => $t ) $a[ 'maz_ia_align_' . $k ] = 'Align as: ' . $t['label'];
		$a['maz_ia_exclude'] = 'Image Align: mark as not a hanger photo';
		$a['maz_ia_revert']  = 'Image Align: revert to original';
		return $a;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( strpos( $action, 'maz_ia_' ) !== 0 ) return $redirect;
		$type = '';
		if ( strpos( $action, 'maz_ia_align_' ) === 0 ) { $what = 'align'; $type = sanitize_key( substr( $action, strlen( 'maz_ia_align_' ) ) ); if ( ! isset( Maz_IA_Settings::types()[ $type ] ) ) return $redirect; }
		elseif ( $action === 'maz_ia_exclude' ) $what = 'exclude';
		elseif ( $action === 'maz_ia_revert' )  $what = 'revert';
		else return $redirect;

		$ids = array_filter( array_map( 'intval', (array) $ids ), function ( $id ) { return $id && wp_attachment_is_image( $id ) && Maz_IA_Files::user_can( $id ); } );
		$ids = array_values( $ids );
		$denied = count( (array) $ids ) === 0;
		$redirect = remove_query_arg( [ 'maz_ia_ok', 'maz_ia_fail', 'maz_ia_job', 'maz_ia_denied' ], $redirect );
		if ( $denied ) return add_query_arg( 'maz_ia_denied', 1, $redirect );

		$o = Maz_IA_Settings::opts();
		if ( count( $ids ) > (int) $o['sync_limit'] ) {
			$job = Maz_IA_Queue::enqueue( $ids, $what, $type );
			return add_query_arg( 'maz_ia_job', $job, $redirect );
		}
		$ok = 0; $fail = 0;
		foreach ( $ids as $id ) {
			if ( $what === 'exclude' ) { Maz_IA_Files::set_excluded( $id, true ); $ok++; continue; }
			$r = $what === 'revert' ? Maz_IA_Files::revert( $id ) : Maz_IA_Files::align( $id, $type );
			is_wp_error( $r ) ? $fail++ : $ok++;
		}
		return add_query_arg( [ 'maz_ia_ok' => $ok, 'maz_ia_fail' => $fail ], $redirect );
	}

	public static function notices() {
		if ( isset( $_GET['maz_ia_denied'] ) ) echo '<div class="notice notice-error is-dismissible"><p>Image Align: none of the selected items are images you can edit.</p></div>';
		if ( isset( $_GET['maz_ia_ok'] ) ) printf( '<div class="notice notice-success is-dismissible"><p>Image Align: %d done, %d failed.</p></div>', (int) $_GET['maz_ia_ok'], (int) $_GET['maz_ia_fail'] );
		if ( isset( $_GET['maz_ia_job'] ) ) echo '<div class="notice notice-info"><p>Image Align: the selection was queued for background processing — progress is shown below.</p></div>';
		$screen = get_current_screen();
		if ( $screen && $screen->id === 'upload' && current_user_can( 'upload_files' ) ) echo '<div id="maz-ia-jobs"></div>';
	}

	/* ------------------------------------------------------------ AJAX */

	/** Shared guard: nonce + per-attachment capability. Returns the id. */
	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! $id || get_post_type( $id ) !== 'attachment' || ! wp_attachment_is_image( $id ) ) wp_send_json_error( 'Not an image attachment' );
		if ( ! Maz_IA_Files::user_can( $id ) ) wp_send_json_error( 'Permission denied' );
		return $id;
	}

	private static function type_from_post() {
		$type = sanitize_key( $_POST['type'] ?? '' );
		return isset( Maz_IA_Settings::types()[ $type ] ) ? $type : '';
	}

	public static function ajax_align() {
		$id = self::guard();
		if ( ( $_POST['type'] ?? '' ) === self::EXCLUDE_TYPE ) { Maz_IA_Files::set_excluded( $id, true ); wp_send_json_success( [ 'id' => $id, 'excluded' => 1, 'state' => Maz_IA_Files::state( $id ) ] ); }
		$r = Maz_IA_Files::align( $id, self::type_from_post() );
		if ( is_wp_error( $r ) ) wp_send_json_error( $r->get_error_message() );
		$r['flag_text'] = self::flags_html( $r['flags'] );
		wp_send_json_success( $r );
	}

	/** Before/after preview: renders to a temp file, returns it inline, deletes it. Nothing is written to the attachment. */
	public static function ajax_preview() {
		$id = self::guard();
		if ( ( $_POST['type'] ?? '' ) === self::EXCLUDE_TYPE ) wp_send_json_error( 'Choose a garment type to preview' );
		$r = Maz_IA_Files::align( $id, self::type_from_post(), [ 'preview' => true ] );
		if ( is_wp_error( $r ) ) wp_send_json_error( $r->get_error_message() );
		$data = @file_get_contents( $r['tmp'] ); @unlink( $r['tmp'] );
		if ( ! $data ) wp_send_json_error( 'Preview could not be read' );
		$orig = wp_get_attachment_image_src( $id, 'large' );
		$labels = Maz_IA_Engine::flag_labels();
		wp_send_json_success( [
			'id' => $id, 'type' => $r['type'], 'result' => 'data:image/jpeg;base64,' . base64_encode( $data ),
			'original' => $orig ? $orig[0] : wp_get_attachment_url( $id ),
			'ball' => $r['ball'], 'scale' => $r['scale'], 'engine' => $r['engine'], 'source' => $r['source'], 'ms' => $r['ms'], 'src_size' => $r['src_size'],
			'flags' => array_map( function ( $f ) use ( $labels ) { return $labels[ $f ] ?? $f; }, $r['flags'] ),
		] );
	}

	public static function ajax_revert() {
		$id = self::guard();
		$r = Maz_IA_Files::revert( $id );
		if ( is_wp_error( $r ) ) wp_send_json_error( $r->get_error_message() );
		wp_send_json_success( $r );
	}

	public static function ajax_exclude() {
		$id = self::guard();
		Maz_IA_Files::set_excluded( $id, ! empty( $_POST['on'] ) );
		wp_send_json_success( [ 'id' => $id, 'state' => Maz_IA_Files::state( $id ) ] );
	}

	/** Job progress / cancel / dismiss. */
	public static function ajax_job() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( 'Permission denied' );
		$op  = sanitize_key( $_POST['op'] ?? 'status' );
		$jid = preg_replace( '/[^a-f0-9]/', '', (string) ( $_POST['job'] ?? '' ) );
		if ( $op === 'cancel' || $op === 'dismiss' ) {
			$job = Maz_IA_Queue::get( $jid );
			if ( ! $job || ( (int) $job['user'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) wp_send_json_error( 'Not your job' );
			$op === 'cancel' ? Maz_IA_Queue::cancel( $jid ) : Maz_IA_Queue::dismiss( $jid );
		}
		wp_send_json_success( [ 'jobs' => Maz_IA_Queue::active_jobs() ] );
	}
}

endif;
