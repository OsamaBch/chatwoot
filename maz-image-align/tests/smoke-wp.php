<?php
/**
 * Smoke test of the WordPress commit path with minimal WP stubs (no database, no WordPress install).
 *
 *   php tests/smoke-wp.php
 *
 * Exercises Maz_IA_Files::align → redo → revert → delete_attachment on a synthetic photo and checks:
 * one backup, redo starts from the backup, old sizes removed, no temp files, revert restores the
 * original bytes and extension, delete removes the backup, excluded images are refused,
 * a user without edit rights is refused by the AJAX guard logic (user_can).
 */
if ( PHP_SAPI !== 'cli' ) exit( 2 );

/* ---------------------------------------------------------------- WP stubs */
$GLOBALS['wp_meta'] = []; $GLOBALS['wp_posts'] = []; $GLOBALS['wp_options'] = []; $GLOBALS['wp_actions'] = [];
$GLOBALS['uploads'] = sys_get_temp_dir() . '/maz-smoke-' . getmypid();
$GLOBALS['cap_edit'] = true;
@mkdir( $GLOBALS['uploads'] . '/2026/10', 0777, true );
define( 'ABSPATH', $GLOBALS['uploads'] . '/wp/' ); @mkdir( ABSPATH . 'wp-admin/includes', 0777, true ); file_put_contents( ABSPATH . 'wp-admin/includes/image.php', '<?php' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { public $c; public $m; function __construct( $c, $m ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action() {} function add_filter() {} function remove_filter() {} function do_action( $h ) { $GLOBALS['wp_actions'][] = $h; } function apply_filters( $h, $v ) { return $v; }
function get_post_meta( $id, $k, $single = true ) { return $GLOBALS['wp_meta'][ $id ][ $k ] ?? ( $single ? '' : [] ); }
function update_post_meta( $id, $k, $v ) { $GLOBALS['wp_meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['wp_meta'][ $id ][ $k ] ); return true; }
function update_meta_cache() {}
function get_post_type( $id ) { return isset( $GLOBALS['wp_posts'][ $id ] ) ? 'attachment' : false; }
function wp_attachment_is_image( $id ) { return isset( $GLOBALS['wp_posts'][ $id ] ); }
function get_attached_file( $id ) { return $GLOBALS['uploads'] . '/' . get_post_meta( $id, '_wp_attached_file' ); }
function update_attached_file( $id, $f ) { update_post_meta( $id, '_wp_attached_file', Maz_IA_Files::relative_to( $f, $GLOBALS['uploads'] ) ); }
function wp_upload_dir() { return [ 'basedir' => $GLOBALS['uploads'], 'baseurl' => 'https://example.test/wp-content/uploads' ]; }
function wp_get_attachment_url( $id ) { return 'https://example.test/wp-content/uploads/' . get_post_meta( $id, '_wp_attached_file' ); }
function wp_get_attachment_metadata( $id ) { return get_post_meta( $id, '_wp_attachment_metadata' ) ?: []; }
function wp_update_attachment_metadata( $id, $m ) { update_post_meta( $id, '_wp_attachment_metadata', $m ); }
function wp_update_post( $a ) { $GLOBALS['wp_posts'][ $a['ID'] ]['mime'] = $a['post_mime_type']; }
function wp_generate_attachment_metadata( $id, $file ) { // fake one thumbnail next to the file
	$i = pathinfo( $file ); $thumb = $i['filename'] . '-150x150.' . $i['extension']; copy( $file, $i['dirname'] . '/' . $thumb ); file_put_contents( $i['dirname'] . '/' . $thumb . '.webp', 'x' );
	return [ 'file' => Maz_IA_Files::relative_to( $file, $GLOBALS['uploads'] ), 'sizes' => [ 'thumbnail' => [ 'file' => $thumb ] ] ];
}
function wp_tempnam( $p ) { $f = tempnam( sys_get_temp_dir(), $p ); return $f; }
function wp_basename( $p ) { return basename( $p ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_check_filetype( $f ) { $e = strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ); return [ 'type' => $e === 'png' ? 'image/png' : 'image/jpeg' ]; }
function wp_get_post_parent_id() { return 0; }
function clean_post_cache() {} function clean_attachment_cache() {}
function get_current_user_id() { return 7; }
function current_user_can( $cap ) { return $cap === 'upload_files' ? true : $GLOBALS['cap_edit']; }
function get_option( $k, $d = false ) { return $GLOBALS['wp_options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['wp_options'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function add_settings_error() {}
class FakeDB { public $postmeta = 'pm'; function prepare( $q, $v ) { return $v; } function get_col( $rel ) { $o = []; foreach ( $GLOBALS['wp_meta'] as $id => $m ) if ( ( $m['_wp_attached_file'] ?? '' ) === $rel ) $o[] = $id; return $o; } }
$GLOBALS['wpdb'] = new FakeDB();

require dirname( __DIR__ ) . '/includes/class-maz-ia-engine.php';
require dirname( __DIR__ ) . '/includes/class-maz-ia-settings.php';
require dirname( __DIR__ ) . '/includes/class-maz-ia-files.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) $pass++; else { $fail++; echo "  ✗ $m\n"; } }
function files( $dir ) { $f = array_map( 'basename', glob( "$dir/*" ) ); sort( $f ); return $f; }

/* ---------------------------------------------------------------- a synthetic hanger photo as PNG (extension change path) */
$dir = $GLOBALS['uploads'] . '/2026/10';
$W = 2194; $H = 2560; $im = imagecreatetruecolor( $W, $H ); imagefill( $im, 0, 0, imagecolorallocate( $im, 0xE7, 0xE7, 0xE7 ) );
$wood = imagecolorallocate( $im, 120, 80, 40 ); $c = imagecolorallocate( $im, 60, 80, 140 );
imagefilledellipse( $im, 1097, 420, 130, 130, $wood ); imagefilledpolygon( $im, [ 1090, 485, 1104, 485, 1500, 590, 1500, 606, 694, 606, 694, 590 ], $wood );
imagefilledrectangle( $im, 650, 590, 1544, 2300, $c ); imagefilledrectangle( $im, 520, 590, 1674, 1100, $c );
imagepng( $im, "$dir/photo.png" ); imagedestroy( $im );
$orig_md5 = md5_file( "$dir/photo.png" );
$GLOBALS['wp_posts'][11] = [ 'mime' => 'image/png' ];
update_post_meta( 11, '_wp_attached_file', '2026/10/photo.png' );
wp_update_attachment_metadata( 11, wp_generate_attachment_metadata( 11, "$dir/photo.png" ) ); // pre-existing sizes + webp sidecar
$tmp_before = glob( sys_get_temp_dir() . '/maz-align*' );

echo "align (png → jpg)\n";
$r = Maz_IA_Files::align( 11, 'jacket' );
ok( ! is_wp_error( $r ), 'align failed: ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
ok( get_post_meta( 11, '_wp_attached_file' ) === '2026/10/photo.jpg', 'attached file not switched to .jpg: ' . get_post_meta( 11, '_wp_attached_file' ) );
ok( $GLOBALS['wp_posts'][11]['mime'] === 'image/jpeg', 'mime not updated' );
ok( get_post_meta( 11, '_maz_orig_file' ) === '2026/10/photo-orig.png', 'backup meta: ' . get_post_meta( 11, '_maz_orig_file' ) );
ok( md5_file( "$dir/photo-orig.png" ) === $orig_md5, 'backup is not the pristine original' );
$expect = [ 'photo-150x150.jpg', 'photo-150x150.jpg.webp', 'photo-orig.png', 'photo.jpg' ];
ok( files( $dir ) === $expect, 'files after align: ' . implode( ', ', files( $dir ) ) );
$sz = getimagesize( "$dir/photo.jpg" ); ok( $sz[0] === 2194 && $sz[1] === 2560, 'output size' );
ok( (int) get_post_meta( 11, '_maz_aligned' ) > 0 && get_post_meta( 11, '_maz_type' ) === 'jacket', 'meta' );
$h = Maz_IA_Files::history( 11 ); ok( count( $h ) === 1 && $h[0]['action'] === 'align' && ! empty( $h[0]['settings'] ), 'history entry' );
$first_out = md5_file( "$dir/photo.jpg" );

echo "redo (lossless: from backup, no second backup)\n";
$r = Maz_IA_Files::align( 11, 'set' );
ok( ! is_wp_error( $r ), 'redo failed' );
ok( files( $dir ) === $expect, 'files after redo: ' . implode( ', ', files( $dir ) ) );
ok( md5_file( "$dir/photo-orig.png" ) === $orig_md5, 'backup changed on redo' );
ok( md5_file( "$dir/photo.jpg" ) !== $first_out, 'redo with another type produced the same file' );
ok( count( Maz_IA_Files::history( 11 ) ) === 2, 'history length' );

echo "preview (no side effects)\n";
$snap = files( $dir ); $meta_snap = $GLOBALS['wp_meta'][11];
$r = Maz_IA_Files::align( 11, 'tshirt', [ 'preview' => true ] );
ok( ! is_wp_error( $r ) && is_file( $r['tmp'] ), 'preview failed' ); if ( ! is_wp_error( $r ) ) @unlink( $r['tmp'] );
ok( files( $dir ) === $snap && $GLOBALS['wp_meta'][11] === $meta_snap, 'preview touched the attachment' );

echo "exclusion + capability\n";
Maz_IA_Files::set_excluded( 11, true );
$r = Maz_IA_Files::align( 11, 'set' ); ok( is_wp_error( $r ) && $r->c === 'maz_excluded', 'excluded image was processed' );
Maz_IA_Files::set_excluded( 11, false );
$GLOBALS['cap_edit'] = false; ok( ! Maz_IA_Files::user_can( 11 ), 'user without edit_post passed user_can' ); $GLOBALS['cap_edit'] = true;

echo "tampered backup meta (path outside uploads) is ignored\n";
update_post_meta( 11, '_maz_orig_file', '../../../../etc/passwd' );
ok( Maz_IA_Files::backup_for( 11 ) === '', 'outside path accepted' );
update_post_meta( 11, '_maz_orig_file', '2026/10/photo-orig.png' );

echo "revert\n";
$r = Maz_IA_Files::revert( 11 );
ok( ! is_wp_error( $r ), 'revert failed: ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
ok( files( $dir ) === [ 'photo-150x150.png', 'photo-150x150.png.webp', 'photo.png' ], 'files after revert: ' . implode( ', ', files( $dir ) ) );
ok( md5_file( "$dir/photo.png" ) === $orig_md5, 'reverted file is not the original' );
ok( get_post_meta( 11, '_wp_attached_file' ) === '2026/10/photo.png' && $GLOBALS['wp_posts'][11]['mime'] === 'image/png', 'revert meta' );
ok( get_post_meta( 11, '_maz_aligned' ) === '' && get_post_meta( 11, '_maz_orig_file' ) === '', 'align meta not cleared' );

echo "delete_attachment removes the backup\n";
$r = Maz_IA_Files::align( 11, 'robe' ); ok( ! is_wp_error( $r ) && is_file( "$dir/photo-orig.png" ), 'realign' );
Maz_IA_Files::on_delete_attachment( 11 );
ok( ! is_file( "$dir/photo-orig.png" ), 'backup left behind after delete' );

echo "no-ball photo is refused, nothing written\n";
$im = imagecreatetruecolor( 800, 933 ); imagefill( $im, 0, 0, imagecolorallocate( $im, 0xF5, 0xF5, 0xF5 ) );
imagefilledellipse( $im, 400, 560, 500, 180, imagecolorallocate( $im, 40, 30, 20 ) ); imagejpeg( $im, "$dir/shoe.jpg", 95 ); imagedestroy( $im );
$GLOBALS['wp_posts'][12] = [ 'mime' => 'image/jpeg' ]; update_post_meta( 12, '_wp_attached_file', '2026/10/shoe.jpg' ); $m = md5_file( "$dir/shoe.jpg" );
$r = Maz_IA_Files::align( 12, 'default' );
ok( is_wp_error( $r ) && $r->c === 'maz_no_ball', 'shoe not refused: ' . ( is_wp_error( $r ) ? $r->c : 'ok' ) );
ok( md5_file( "$dir/shoe.jpg" ) === $m && ! is_file( "$dir/shoe-orig.jpg" ), 'refused image was touched' );

$tmp_after = glob( sys_get_temp_dir() . '/maz-align*' );
ok( $tmp_after === $tmp_before, 'temp leftovers: ' . implode( ', ', array_diff( $tmp_after, $tmp_before ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exec( 'rm -rf ' . escapeshellarg( $GLOBALS['uploads'] ) );
exit( $fail ? 1 : 0 );
