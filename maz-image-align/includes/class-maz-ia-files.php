<?php
/**
 * Mazyoud Image Align — attachment / file layer.
 *
 * Owns everything that touches the uploads folder: backups, atomic moves, replacing the
 * attachment file, regenerating sizes, revert, and cleanup on delete.
 * The static helpers at the top are WordPress-free so tests/run.php can exercise them.
 */

if ( ! class_exists( 'Maz_IA_Files' ) ) :

final class Maz_IA_Files {

	const META_ALIGNED = '_maz_aligned';
	const META_TYPE    = '_maz_type';
	const META_ORIG    = '_maz_orig_file';   // backup path, RELATIVE to the uploads basedir
	const META_FLAGS   = '_maz_flags';
	const META_HISTORY = '_maz_history';
	const META_EXCLUDE = '_maz_exclude';
	const HISTORY_MAX  = 20;

	/* ====================================================== pure helpers (no WP) */

	/** `dir/name-orig.ext` for a source file. */
	public static function backup_path( $src ) {
		$i = pathinfo( $src );
		return $i['dirname'] . '/' . $i['filename'] . '-orig' . ( isset( $i['extension'] ) && $i['extension'] !== '' ? '.' . $i['extension'] : '' );
	}

	/**
	 * Make sure exactly one pristine backup exists for $src.
	 * $existing: a previously recorded backup path (already validated) or ''.
	 * $adopt_untracked: reuse an untracked `-orig` file if one is already there (caller decides).
	 * Returns the backup path, or false on copy failure.
	 */
	public static function ensure_backup( $src, $existing = '', $adopt_untracked = true ) {
		if ( $existing && is_file( $existing ) && filesize( $existing ) > 0 ) return $existing;
		$bak = self::backup_path( $src );
		if ( is_file( $bak ) ) {
			if ( $adopt_untracked && @getimagesize( $bak ) ) return $bak;
			// untracked file we must not touch: pick a free sibling name
			$i = pathinfo( $bak ); $n = 1;
			do { $bak = $i['dirname'] . '/' . $i['filename'] . '-' . $n . ( isset( $i['extension'] ) ? '.' . $i['extension'] : '' ); $n++; } while ( is_file( $bak ) );
		}
		if ( ! @copy( $src, $bak ) || filesize( $bak ) !== filesize( $src ) ) { @unlink( $bak ); return false; }
		return $bak;
	}

	/** rename() with copy+unlink fallback (temp dir may be on another filesystem). Never leaves the temp file behind. */
	public static function move( $from, $to ) {
		if ( @rename( $from, $to ) ) { @chmod( $to, 0644 ); return true; }
		if ( @copy( $from, $to ) ) { @unlink( $from ); @chmod( $to, 0644 ); return true; }
		@unlink( $from );
		return false;
	}

	/**
	 * Resolve a stored backup path (relative to $basedir, or legacy absolute) to a real file inside $basedir.
	 * Returns the absolute path or '' — never a path outside the uploads folder.
	 */
	public static function resolve_inside( $stored, $basedir ) {
		$stored = (string) $stored;
		if ( $stored === '' ) return '';
		$base = realpath( $basedir );
		if ( ! $base ) return '';
		$abs  = ( $stored[0] === '/' || preg_match( '#^[A-Za-z]:[\\\\/]#', $stored ) ) ? $stored : $base . '/' . ltrim( $stored, '/\\' );
		$real = realpath( $abs );
		if ( ! $real || ! is_file( $real ) ) return '';
		$base = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
		$realn = str_replace( '\\', '/', $real );
		if ( strpos( $realn, $base ) !== 0 ) return '';
		return $real;
	}

	public static function relative_to( $abs, $basedir ) {
		$base = rtrim( str_replace( '\\', '/', realpath( $basedir ) ), '/' ) . '/';
		$abs  = str_replace( '\\', '/', realpath( $abs ) ?: $abs );
		return strpos( $abs, $base ) === 0 ? substr( $abs, strlen( $base ) ) : $abs;
	}

	/* ====================================================== WordPress layer */

	public static function init() {
		add_action( 'delete_attachment', [ __CLASS__, 'on_delete_attachment' ], 10, 1 );
	}

	public static function basedir() { $u = wp_upload_dir( null, false ); return $u['basedir']; }

	/** Validated absolute backup path for an attachment, or ''. Migrates legacy absolute meta to relative. */
	public static function backup_for( $id ) {
		$stored = get_post_meta( $id, self::META_ORIG, true );
		if ( ! $stored ) return '';
		$abs = self::resolve_inside( $stored, self::basedir() );
		if ( ! $abs ) return '';
		$rel = self::relative_to( $abs, self::basedir() );
		if ( $rel !== $stored ) update_post_meta( $id, self::META_ORIG, $rel );
		return $abs;
	}

	public static function is_excluded( $id ) { return (bool) get_post_meta( $id, self::META_EXCLUDE, true ); }

	public static function set_excluded( $id, $on ) {
		if ( $on ) { update_post_meta( $id, self::META_EXCLUDE, 1 ); self::push_history( $id, [ 'action' => 'exclude' ] ); }
		else { delete_post_meta( $id, self::META_EXCLUDE ); self::push_history( $id, [ 'action' => 'include' ] ); }
	}

	public static function push_history( $id, $entry ) {
		$h = get_post_meta( $id, self::META_HISTORY, true );
		if ( ! is_array( $h ) ) $h = [];
		$entry = array_merge( [ 'time' => time(), 'user' => get_current_user_id() ], $entry );
		array_unshift( $h, $entry );
		update_post_meta( $id, self::META_HISTORY, array_slice( $h, 0, self::HISTORY_MAX ) );
	}

	public static function history( $id ) { $h = get_post_meta( $id, self::META_HISTORY, true ); return is_array( $h ) ? $h : []; }

	/** Current user may run the plugin on this attachment. */
	public static function user_can( $id ) {
		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	}

	/** Summary used by the UI (one call, one meta cache hit). */
	public static function state( $id ) {
		return [
			'id'       => (int) $id,
			'type'     => get_post_meta( $id, self::META_TYPE, true ) ?: 'default',
			'done'     => (int) get_post_meta( $id, self::META_ALIGNED, true ),
			'excluded' => self::is_excluded( $id ),
			'flags'    => (array) ( get_post_meta( $id, self::META_FLAGS, true ) ?: [] ),
			'backup'   => self::backup_for( $id ) !== '',
		];
	}

	/* ------------------------------------------------------------ align */

	/**
	 * Run the engine on an attachment. $opts: ['preview' => bool] renders to a temp file and returns its path
	 * without touching the attachment. Returns array or WP_Error.
	 */
	public static function align( $id, $type, $opts = [] ) {
		$id = (int) $id;
		if ( ! $id || get_post_type( $id ) !== 'attachment' || ! wp_attachment_is_image( $id ) ) return new WP_Error( 'maz_not_image', 'Not an image attachment' );
		if ( self::is_excluded( $id ) ) return new WP_Error( 'maz_excluded', 'This image is marked "Not a hanger photo" — unmark it first' );
		$src = get_attached_file( $id );
		if ( ! $src || ! is_file( $src ) ) return new WP_Error( 'maz_missing', 'File not found on disk' );

		$types = Maz_IA_Settings::types();
		if ( ! $type || ! isset( $types[ $type ] ) ) $type = get_post_meta( $id, self::META_TYPE, true ) ?: 'default';
		if ( ! isset( $types[ $type ] ) ) $type = 'default';

		$o = Maz_IA_Settings::opts();
		$o['fill'] = $types[ $type ]['fill'];
		$preview = ! empty( $opts['preview'] );
		if ( $preview ) {
			// Same geometry, smaller canvas: everything scales proportionally.
			$k = min( 1, 700 / max( 1, $o['canvas_w'] ) );
			foreach ( [ 'canvas_w', 'canvas_h', 'top_margin', 'bottom_min', 'side_min' ] as $key ) $o[ $key ] = max( 1, (int) round( $o[ $key ] * $k ) );
			$o['quality'] = 80;
		}

		// Redo is lossless: always start from the pristine backup when there is one.
		$backup = self::backup_for( $id );
		$input  = $backup ?: $src;

		if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'image' );
		$tmp = wp_tempnam( 'maz-align' );
		if ( ! $tmp ) return new WP_Error( 'maz_tmp', 'Cannot create a temporary file' );

		$r = Maz_IA_Engine::process( $input, $tmp, $o );
		if ( empty( $r['ok'] ) ) { @unlink( $tmp ); return new WP_Error( 'maz_' . $r['code'], $r['error'] ); }
		$r['type'] = $type;

		if ( $preview ) { $r['tmp'] = $tmp; return $r; }

		/* ---- commit: backup, remove old files, move output in place, regenerate ---- */
		if ( ! $backup ) {
			$untracked = self::backup_path( $src );
			$adopt = is_file( $untracked ) && ! self::file_owned_by_other_attachment( $untracked, $id );
			$backup = self::ensure_backup( $src, '', $adopt );
			if ( ! $backup ) { @unlink( $tmp ); return new WP_Error( 'maz_backup', 'Could not write the backup copy — nothing was changed' ); }
			update_post_meta( $id, self::META_ORIG, self::relative_to( $backup, self::basedir() ) );
		}

		$old_url = wp_get_attachment_url( $id );
		$removed = self::delete_generated_files( $id, $src );
		$info = pathinfo( $src );
		$dest = $info['dirname'] . '/' . $info['filename'] . '.jpg'; // output is always JPEG
		if ( $dest !== $src && is_file( $src ) ) { @unlink( $src ); self::delete_sidecars( $src ); }
		if ( ! self::move( $tmp, $dest ) ) return new WP_Error( 'maz_move', 'Could not move the aligned file into the uploads folder' );

		self::install_file( $id, $dest, 'image/jpeg' );
		update_post_meta( $id, self::META_ALIGNED, time() );
		update_post_meta( $id, self::META_TYPE, $type );
		if ( $r['flags'] ) update_post_meta( $id, self::META_FLAGS, $r['flags'] ); else delete_post_meta( $id, self::META_FLAGS );
		self::push_history( $id, [
			'action' => 'align', 'type' => $type, 'engine' => $r['engine'], 'ms' => $r['ms'],
			'settings' => Maz_IA_Engine::settings_hash( $o, $types ), 'flags' => $r['flags'], 'ball' => $r['ball']['confidence'],
		] );
		self::purge_caches( $id, array_merge( [ $old_url, wp_get_attachment_url( $id ) ], $removed ) );
		do_action( 'maz_ia_aligned', $id, $dest, $r );

		$r['id'] = $id; $r['url'] = wp_get_attachment_url( $id ) . '?v=' . time();
		$r['state'] = self::state( $id );
		return $r;
	}

	/** Swap the pristine backup back in place and clear alignment meta. */
	public static function revert( $id ) {
		$id = (int) $id;
		if ( ! $id || get_post_type( $id ) !== 'attachment' ) return new WP_Error( 'maz_not_image', 'Not an attachment' );
		$backup = self::backup_for( $id );
		if ( ! $backup ) return new WP_Error( 'maz_no_backup', 'No backup to revert to' );
		$cur = get_attached_file( $id );
		if ( ! $cur ) return new WP_Error( 'maz_missing', 'Attachment has no file' );

		$old_url = wp_get_attachment_url( $id );
		$removed = self::delete_generated_files( $id, $cur );
		$bi = pathinfo( $backup ); $ci = pathinfo( $cur );
		$dest = $ci['dirname'] . '/' . $ci['filename'] . ( isset( $bi['extension'] ) ? '.' . $bi['extension'] : '' );
		if ( is_file( $cur ) ) { @unlink( $cur ); self::delete_sidecars( $cur ); }
		if ( ! self::move( $backup, $dest ) ) return new WP_Error( 'maz_move', 'Could not move the backup back' );

		$ft = wp_check_filetype( $dest );
		self::install_file( $id, $dest, $ft['type'] ?: 'image/jpeg' );
		delete_post_meta( $id, self::META_ALIGNED );
		delete_post_meta( $id, self::META_ORIG );
		delete_post_meta( $id, self::META_FLAGS );
		self::push_history( $id, [ 'action' => 'revert' ] );
		self::purge_caches( $id, array_merge( [ $old_url, wp_get_attachment_url( $id ) ], $removed ) );
		do_action( 'maz_ia_reverted', $id, $dest );
		return [ 'id' => $id, 'url' => wp_get_attachment_url( $id ) . '?v=' . time(), 'state' => self::state( $id ) ];
	}

	/** Remove the backup when the attachment itself is deleted. */
	public static function on_delete_attachment( $id ) {
		$backup = self::backup_for( $id );
		if ( $backup && ! self::file_owned_by_other_attachment( $backup, $id ) ) @unlink( $backup );
	}

	/* ------------------------------------------------------------ internals */

	/** Point the attachment at $file, set mime, regenerate every size from scratch. */
	private static function install_file( $id, $file, $mime ) {
		update_attached_file( $id, $file );
		wp_update_post( [ 'ID' => $id, 'post_mime_type' => $mime ] );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		// The aligned file IS the full-size image: never let WP shrink it into a "-scaled" copy.
		add_filter( 'big_image_size_threshold', '__return_false', 99 );
		$meta = wp_generate_attachment_metadata( $id, $file );
		remove_filter( 'big_image_size_threshold', '__return_false', 99 );
		wp_update_attachment_metadata( $id, is_array( $meta ) ? $meta : [] );
		clean_post_cache( $id );
		clean_attachment_cache( $id );
	}

	/**
	 * Delete every generated file of the attachment (sizes, -scaled original, WebP/AVIF sidecars).
	 * Returns the URLs of removed files so caches can be purged.
	 */
	private static function delete_generated_files( $id, $main ) {
		$meta = wp_get_attachment_metadata( $id );
		$dir  = dirname( $main );
		$files = [];
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) foreach ( $meta['sizes'] as $s ) if ( ! empty( $s['file'] ) ) $files[] = $dir . '/' . wp_basename( $s['file'] );
		if ( ! empty( $meta['original_image'] ) ) {
			$orig = $dir . '/' . wp_basename( $meta['original_image'] );
			if ( realpath( $orig ) !== realpath( $main ) ) $files[] = $orig;
		}
		$files = apply_filters( 'maz_ia_generated_files', array_unique( $files ), $id, $main, $meta );
		$base_url = trailingslashit( dirname( wp_get_attachment_url( $id ) ) );
		$urls = [];
		$backup = self::backup_for( $id );
		foreach ( $files as $f ) {
			if ( $backup && realpath( $f ) === realpath( $backup ) ) continue; // never the pristine copy
			if ( is_file( $f ) ) { @unlink( $f ); $urls[] = $base_url . wp_basename( $f ); }
			self::delete_sidecars( $f );
		}
		return $urls;
	}

	/** `file.jpg.webp`, `file.webp`, `file.jpg.avif`, `file.avif` written by WebP/AVIF converter plugins. */
	private static function delete_sidecars( $f ) {
		$noext = preg_replace( '/\.[A-Za-z0-9]+$/', '', $f );
		foreach ( [ $f . '.webp', $f . '.avif', $noext . '.webp', $noext . '.avif' ] as $s ) if ( $s !== $f && is_file( $s ) ) @unlink( $s );
	}

	private static function file_owned_by_other_attachment( $abs, $self_id ) {
		global $wpdb;
		$rel = self::relative_to( $abs, self::basedir() );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 2", $rel ) );
		foreach ( $ids as $i ) if ( (int) $i !== (int) $self_id ) return true;
		return false;
	}

	/** WP Rocket (page cache + CDN/RocketCDN) and the product page. Other caches: hook 'maz_ia_purge'. */
	private static function purge_caches( $id, array $urls ) {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		$parent = wp_get_post_parent_id( $id );
		if ( function_exists( 'rocket_clean_files' ) ) rocket_clean_files( $urls );
		if ( $parent && function_exists( 'rocket_clean_post' ) ) rocket_clean_post( $parent );
		do_action( 'maz_ia_purge', $id, $urls, $parent );
	}
}

endif;
