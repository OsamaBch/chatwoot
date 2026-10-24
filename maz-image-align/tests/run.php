<?php
/**
 * Mazyoud Image Align — CLI test runner (no WordPress needed).
 *
 *   php tests/run.php --src=/path/to/photos [--out=/tmp/maz-out] [--engine=gd|imagick] [--keep] [--synthetic]
 *
 * For every source image × every garment type it runs the engine and asserts:
 *   - output size == canvas
 *   - hanger-ball top row == top_margin ± 1 px
 *   - no subject pixel within side_min of the left/right edges or bottom_min of the bottom
 *   - JPEG quality (from the quantisation tables) ≥ setting
 *   - no temp files left behind, nothing but the expected outputs in the out folder
 *   - the backup helper creates exactly one `-orig` file, however often it is called
 * Synthetic hanger photos (grey backgrounds, white garment, off-centre hanger, PNG with alpha,
 * a mannequin and a shoe that must be refused) are generated when --src is omitted/empty or --synthetic is set.
 * Exit code 0 = all green.
 */

if ( PHP_SAPI !== 'cli' ) { fwrite( STDERR, "CLI only\n" ); exit( 2 ); }
require dirname( __DIR__ ) . '/includes/class-maz-ia-engine.php';
require dirname( __DIR__ ) . '/includes/class-maz-ia-files.php';

$args = [];
foreach ( array_slice( $argv, 1 ) as $a ) { if ( preg_match( '/^--([a-z]+)(?:=(.*))?$/', $a, $m ) ) $args[ $m[1] ] = isset( $m[2] ) ? $m[2] : true; }
$src_dir = isset( $args['src'] ) ? rtrim( $args['src'], '/' ) : '';
$out_dir = isset( $args['out'] ) ? rtrim( $args['out'], '/' ) : sys_get_temp_dir() . '/maz-ia-test-' . getmypid();
$engine  = isset( $args['engine'] ) ? $args['engine'] : Maz_IA_Engine::available();
$keep    = ! empty( $args['keep'] );
if ( ! $engine ) { fwrite( STDERR, "No GD/Imagick available\n" ); exit( 2 ); }
if ( $engine === 'imagick' && ! class_exists( 'Imagick' ) ) { fwrite( STDERR, "Imagick not available\n" ); exit( 2 ); }

$o     = Maz_IA_Engine::defaults();
$o['engine'] = $engine;
$o['max_upscale'] = 300; // synthetic/test sources may be smaller than the canvas; the cap itself is tested separately
$types = Maz_IA_Engine::default_types();
@mkdir( $out_dir, 0777, true );

$pass = 0; $fail = 0; $log = [];
function ok( $cond, $msg ) { global $pass, $fail, $log; if ( $cond ) { $pass++; } else { $fail++; $log[] = $msg; echo "  ✗ $msg\n"; } return $cond; }

/* ------------------------------------------------------------------ sources */
$sources = []; $truth = [];
if ( $src_dir && is_dir( $src_dir ) ) foreach ( glob( $src_dir . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE ) ?: [] as $f ) $sources[ $f ] = null;
if ( ! $sources || ! empty( $args['synthetic'] ) ) {
	echo "Generating synthetic hanger photos…\n";
	foreach ( synthetic_set( $out_dir . '/synthetic' ) as $f => $t ) { $sources[ $f ] = $t; }
}
printf( "Engine: %s · %d source(s) · %d types · out: %s\n", $engine, count( $sources ), count( $types ), $out_dir );

$tmp_before = temp_leftovers();
$t_all = microtime( true );

foreach ( $sources as $src => $t ) {
	$name = pathinfo( $src, PATHINFO_FILENAME );
	echo "\n$name\n";
	$expect_refusal = $t && ! empty( $t['refuse'] );
	$last_flags = [];

	foreach ( $types as $tk => $tv ) {
		$oo = $o; $oo['fill'] = $tv['fill'];
		if ( $t && ! empty( $t['opts'] ) ) $oo = array_merge( $oo, $t['opts'] );
		$dst = "$out_dir/$name--$tk.jpg";
		$r = Maz_IA_Engine::process( $src, $dst, $oo );

		if ( $expect_refusal ) {
			ok( empty( $r['ok'] ) && in_array( $r['code'], [ 'no_ball', 'no_subject' ], true ), "$name/$tk: expected refusal, got " . ( empty( $r['ok'] ) ? $r['code'] : 'ok' ) );
			@unlink( $dst );
			continue;
		}
		if ( ! ok( ! empty( $r['ok'] ), "$name/$tk: engine error: " . ( $r['error'] ?? '?' ) ) ) continue;
		$last_flags = $r['flags'];

		$sz = getimagesize( $dst );
		ok( $sz && $sz[0] === $oo['canvas_w'] && $sz[1] === $oo['canvas_h'], "$name/$tk: size {$sz[0]}×{$sz[1]} ≠ canvas" );

		$chk = check_output( $dst, $oo, $r['bg'] );
		if ( empty( $t['skip_ball'] ) ) ok( $chk['ball_top'] !== null && abs( $chk['ball_top'] - $oo['top_margin'] ) <= 1, "$name/$tk: ball top at " . var_export( $chk['ball_top'], true ) . " (want {$oo['top_margin']}±1)" );
		ok( $chk['side_hits'] === 0, "$name/$tk: {$chk['side_hits']} subject px inside the side margins" );
		ok( $chk['bottom_hits'] === 0, "$name/$tk: {$chk['bottom_hits']} subject px inside the bottom margin" );
		$q = jpeg_quality( $dst );
		ok( $q === null || $q >= $oo['quality'] - 1, "$name/$tk: JPEG quality ≈$q < {$oo['quality']}" );

		if ( $t && isset( $t['bbox'] ) ) { // synthetic: detected bbox must contain the drawn garment (no under-detection)
			[ $bx0, $by0, $bx1, $by1 ] = $r['bbox']; [ $tx0, $ty0, $tx1, $ty1 ] = $t['bbox'];
			ok( $bx0 <= $tx0 + 2 && $bx1 >= $tx1 - 2 && $by1 >= $ty1 - 2, "$name/$tk: bbox [$bx0,$by0,$bx1,$by1] misses garment [$tx0,$ty0,$tx1,$ty1]" );
			if ( empty( $t['skip_ball'] ) ) ok( abs( $r['ball']['y'] - $t['ball_top'] ) <= 8, "$name/$tk: ball detected at {$r['ball']['y']}, drawn at {$t['ball_top']}" );
		}
		if ( $t && isset( $t['flags_expected'] ) ) foreach ( $t['flags_expected'] as $fl ) ok( in_array( $fl, $r['flags'], true ), "$name/$tk: expected flag '$fl' (got " . implode( ',', $r['flags'] ) . ")" );

		printf( "  %-8s %4dms  ball=%-6s scale=%.3f  subject %dx%d @%d,%d  flags=%s  q≈%s\n", $tk, $r['ms'], $r['ball']['confidence'], $r['scale'],
			$r['subject']['w'], $r['subject']['h'], $r['subject']['x'], $r['subject']['y'], $r['flags'] ? implode( ',', $r['flags'] ) : '-', $q === null ? '?' : $q );
	}

	// fill hierarchy: set must be taller than tshirt on the same image
	if ( ! $expect_refusal && empty( $t['opts'] ) && ! array_intersect( [ 'width_limited', 'upscale_capped' ], $last_flags ) && is_file( "$out_dir/$name--set.jpg" ) && is_file( "$out_dir/$name--tshirt.jpg" ) ) {
		$hs = subject_height( "$out_dir/$name--set.jpg", $o ); $ht = subject_height( "$out_dir/$name--tshirt.jpg", $o );
		ok( $hs > $ht, "$name: set height $hs not > tshirt height $ht" );
	}
}

/* ------------------------------------------------------------------ backup helper: exactly one -orig per image */
$bdir = "$out_dir/backup-test"; @mkdir( $bdir ); array_map( 'unlink', glob( "$bdir/*" ) ?: [] );
$first = array_key_first( $sources );
copy( $first, "$bdir/photo." . pathinfo( $first, PATHINFO_EXTENSION ) );
$work = "$bdir/photo." . pathinfo( $first, PATHINFO_EXTENSION );
$b1 = Maz_IA_Files::ensure_backup( $work, '' );
$b2 = Maz_IA_Files::ensure_backup( $work, $b1 );
$b3 = Maz_IA_Files::ensure_backup( $work, '' ); // meta lost: adopts the existing one
ok( $b1 && $b1 === $b2 && $b2 === $b3, "backup: paths differ ($b1 / $b2 / $b3)" );
ok( count( glob( "$bdir/*-orig*" ) ) === 1, 'backup: expected exactly one -orig file, found ' . count( glob( "$bdir/*-orig*" ) ) );
ok( md5_file( $work ) === md5_file( $b1 ), 'backup: content differs from source' );
// path validation never escapes the base dir
ok( Maz_IA_Files::resolve_inside( '../../etc/passwd', $bdir ) === '', 'resolve_inside: traversal not rejected' );
ok( Maz_IA_Files::resolve_inside( '/etc/passwd', $bdir ) === '', 'resolve_inside: absolute outside path not rejected' );
ok( Maz_IA_Files::resolve_inside( basename( $b1 ), $bdir ) === realpath( $b1 ), 'resolve_inside: valid relative path rejected' );

/* ------------------------------------------------------------------ hygiene */
$tmp_after = temp_leftovers();
ok( $tmp_after === $tmp_before, 'temp leftovers: ' . implode( ', ', array_diff( $tmp_after, $tmp_before ) ) );
$unexpected = array_filter( glob( "$out_dir/*" ), function ( $f ) { return is_file( $f ) && ! preg_match( '/--[a-z]+\.jpg$/', $f ); } );
ok( ! $unexpected, 'unexpected files in out dir: ' . implode( ', ', array_map( 'basename', $unexpected ) ) );

printf( "\n%d passed, %d failed · %.1fs · peak memory %d MB\n", $pass, $fail, microtime( true ) - $t_all, memory_get_peak_usage( true ) / 1048576 );
if ( ! $keep && ! $fail ) { foreach ( glob( "$out_dir/*.jpg" ) as $f ) unlink( $f ); array_map( 'unlink', glob( "$bdir/*" ) ); @rmdir( $bdir ); if ( ! $src_dir ) { array_map( 'unlink', glob( "$out_dir/synthetic/*" ) ); @rmdir( "$out_dir/synthetic" ); } @rmdir( $out_dir ); }
else echo "Outputs kept in $out_dir\n";
exit( $fail ? 1 : 0 );

/* ================================================================== checks */

/** Ball top row, and subject pixels inside the protected margins, measured on the output. */
function check_output( $file, $o, $bg ) {
	$im = imagecreatefromjpeg( $file ); $W = imagesx( $im ); $H = imagesy( $im );
	$tb = $o['ball_threshold']; $need = max( 4, (int) round( $W * $o['ball_min_pct'] / 100 ) );
	$band = $o['ball_band_pct'] / 200; $bx0 = (int) floor( $W * ( 0.5 - $band ) ); $bx1 = (int) ceil( $W * ( 0.5 + $band ) );
	$ball_top = null;
	for ( $y = 0; $y < min( $H, $o['top_margin'] + 60 ) && $ball_top === null; $y++ ) {
		$run = 0;
		for ( $x = $bx0; $x < $bx1; $x++ ) {
			$p = imagecolorat( $im, $x, $y );
			$l = 0.299 * ( ( $p >> 16 ) & 255 ) + 0.587 * ( ( $p >> 8 ) & 255 ) + 0.114 * ( $p & 255 );
			if ( $l < $tb ) { if ( ++$run >= $need ) { $ball_top = $y; break; } } else $run = 0;
		}
	}
	// margins: a pixel is "subject" when clearly different from the canvas background
	// (+10 over color_delta so a slightly uneven source background at the paste border is not a false alarm)
	$delta = $o['color_delta'] + 10;
	$is_subj = function ( $x, $y ) use ( $im, $bg, $delta ) {
		$p = imagecolorat( $im, $x, $y );
		return max( abs( ( ( $p >> 16 ) & 255 ) - $bg[0] ), abs( ( ( $p >> 8 ) & 255 ) - $bg[1] ), abs( ( $p & 255 ) - $bg[2] ) ) >= $delta;
	};
	$side = 0; $bottom = 0;
	for ( $y = 0; $y < $H; $y++ ) {
		for ( $x = 0; $x < $o['side_min']; $x++ ) { if ( $is_subj( $x, $y ) ) $side++; if ( $is_subj( $W - 1 - $x, $y ) ) $side++; }
	}
	for ( $y = $H - $o['bottom_min']; $y < $H; $y++ ) for ( $x = 0; $x < $W; $x++ ) if ( $is_subj( $x, $y ) ) $bottom++;
	imagedestroy( $im );
	return [ 'ball_top' => $ball_top, 'side_hits' => $side, 'bottom_hits' => $bottom ];
}

/** Height of the subject on an output (first/last row with clearly non-bg pixels), for the hierarchy check. */
function subject_height( $file, $o ) {
	$im = imagecreatefromjpeg( $file ); $W = imagesx( $im ); $H = imagesy( $im );
	$p = imagecolorat( $im, 2, $H - 3 ); $bg = [ ( $p >> 16 ) & 255, ( $p >> 8 ) & 255, $p & 255 ];
	$first = null; $last = null;
	for ( $y = 0; $y < $H; $y += 2 ) for ( $x = 0; $x < $W; $x += 4 ) {
		$q = imagecolorat( $im, $x, $y );
		if ( max( abs( ( ( $q >> 16 ) & 255 ) - $bg[0] ), abs( ( ( $q >> 8 ) & 255 ) - $bg[1] ), abs( ( $q & 255 ) - $bg[2] ) ) >= $o['color_delta'] + 10 ) { if ( $first === null ) $first = $y; $last = $y; break; }
	}
	imagedestroy( $im );
	return $first === null ? 0 : $last - $first;
}

/** Estimate the libjpeg quality from the luminance quantisation table (same approach as ImageMagick). */
function jpeg_quality( $file ) {
	$d = file_get_contents( $file ); $n = strlen( $d ); $i = 2;
	$std = [ 16,11,10,16,24,40,51,61,12,12,14,19,26,58,60,55,14,13,16,24,40,57,69,56,14,17,22,29,51,87,80,62,18,22,37,56,68,109,103,77,24,35,55,64,81,104,113,92,49,64,78,87,103,121,120,101,72,92,95,98,112,100,103,99 ];
	while ( $i + 4 < $n && ord( $d[ $i ] ) === 0xFF ) {
		$marker = ord( $d[ $i + 1 ] ); $len = ( ord( $d[ $i + 2 ] ) << 8 ) | ord( $d[ $i + 3 ] );
		if ( $marker === 0xDB ) {
			$p = $i + 4; $end = $i + 2 + $len;
			while ( $p < $end ) {
				$pq = ord( $d[ $p ] ) >> 4; $tq = ord( $d[ $p ] ) & 15; $p++;
				$vals = [];
				for ( $k = 0; $k < 64; $k++ ) { if ( $pq ) { $vals[] = ( ord( $d[ $p ] ) << 8 ) | ord( $d[ $p + 1 ] ); $p += 2; } else { $vals[] = ord( $d[ $p ] ); $p++; } }
				if ( $tq === 0 ) {
					$scale = 100 * array_sum( $vals ) / array_sum( $std ); // ≈ libjpeg scale factor (zigzag order ignored: sums are invariant)
					return (int) round( $scale <= 100 ? ( 200 - $scale ) / 2 : 5000 / $scale );
				}
			}
		}
		if ( $marker === 0xDA ) break;
		$i += 2 + $len;
	}
	return null;
}

function temp_leftovers() { $g = glob( sys_get_temp_dir() . '/maz-align*' ) ?: []; sort( $g ); return $g; }

/* ================================================================== synthetic photos */

function synthetic_set( $dir ) {
	@mkdir( $dir, 0777, true );
	$set = [];
	// [name, W, H, bg, garment colour, ball centre x fraction, options]
	$cases = [
		[ 'e7-tshirt-1200',   1200, 1400, [ 0xE7, 0xE7, 0xE7 ], [ 60, 80, 140 ],   0.50, [ 'shape' => 'tshirt' ] ],
		[ 'f5-set-tall',      1200, 1400, [ 0xF5, 0xF5, 0xF5 ], [ 120, 60, 60 ],   0.50, [ 'shape' => 'set', 'dust' => true ] ],
		[ 'f5-white-garment', 1200, 1400, [ 0xF5, 0xF5, 0xF5 ], [ 230, 230, 228 ], 0.50, [ 'shape' => 'tshirt', 'shade' => 12 ] ],
		[ 'small-upscale-cap', 600,  700,  [ 0xE7, 0xE7, 0xE7 ], [ 60, 60, 60 ],    0.50, [ 'shape' => 'tshirt', 'opts' => [ 'max_upscale' => 125 ], 'flags' => [ 'upscale_capped' ], 'skip_ball' => true ] ],
		[ 'e7-offcentre',     1200, 1400, [ 0xE7, 0xE7, 0xE7 ], [ 40, 40, 50 ],    0.36, [ 'shape' => 'jacket' ] ],
		[ 'e7-wide-puffer',   1200, 1400, [ 0xE7, 0xE7, 0xE7 ], [ 30, 90, 60 ],    0.50, [ 'shape' => 'wide', 'flags' => [ 'width_limited' ] ] ],
		[ 'png-alpha',        1000, 1167, null,                 [ 70, 50, 120 ],   0.50, [ 'shape' => 'tshirt', 'png' => true ] ],
		[ 'full-2194x2560',   2194, 2560, [ 0xEB, 0xEA, 0xE8 ], [ 50, 50, 50 ],    0.50, [ 'shape' => 'set', 'shadow' => true ] ],
		[ 'mannequin-refuse', 1200, 1400, [ 0xE7, 0xE7, 0xE7 ], [ 90, 90, 90 ],    0.50, [ 'shape' => 'mannequin', 'refuse' => true ] ],
		[ 'shoe-refuse',      1200, 1400, [ 0xF5, 0xF5, 0xF5 ], [ 40, 30, 20 ],    0.50, [ 'shape' => 'shoe', 'refuse' => true ] ],
	];
	foreach ( $cases as [ $name, $W, $H, $bg, $col, $cxf, $opt ] ) {
		$im = imagecreatetruecolor( $W, $H );
		$png = ! empty( $opt['png'] );
		if ( $png ) { imagealphablending( $im, false ); imagesavealpha( $im, true ); imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) ); imagealphablending( $im, true ); $bgc = [ 0xE7, 0xE7, 0xE7 ]; }
		else { $bgc = $bg; imagefill( $im, 0, 0, imagecolorallocate( $im, $bg[0], $bg[1], $bg[2] ) ); }
		if ( ! $png ) { // subtle noise so the background is not perfectly flat
			for ( $k = 0; $k < $W * $H / 40; $k++ ) { $x = mt_rand( 0, $W - 1 ); $y = mt_rand( 0, $H - 1 ); $v = mt_rand( -3, 3 ); imagesetpixel( $im, $x, $y, imagecolorallocate( $im, $bgc[0] + $v, $bgc[1] + $v, $bgc[2] + $v ) ); }
		}
		if ( ! empty( $opt['shadow'] ) ) imagefilledrectangle( $im, 0, $H - 120, $W, $H, imagecolorallocate( $im, $bgc[0] - 25, $bgc[1] - 25, $bgc[2] - 25 ) ); // dark floor shadow in the ring
		$c   = imagecolorallocate( $im, $col[0], $col[1], $col[2] );
		$wood = imagecolorallocate( $im, 120, 80, 40 );
		$cx  = (int) ( $W * $cxf );
		$shape = $opt['shape'];
		$truth = null;

		if ( in_array( $shape, [ 'tshirt', 'set', 'jacket', 'wide' ], true ) ) {
			$r = (int) ( $W * 0.03 );                  // ball radius 3 % → diameter 6 %
			$ball_top = (int) ( $H * ( $shape === 'set' ? 0.08 : 0.16 ) );
			// hook wire + dust above the ball
			imagesetthickness( $im, max( 2, (int) ( $W / 400 ) ) );
			imagearc( $im, $cx, $ball_top - $r, $r * 2, $r * 2, 180, 360, imagecolorallocate( $im, 90, 90, 95 ) );
			if ( ! empty( $opt['dust'] ) ) imagefilledellipse( $im, $cx + 90, $ball_top - 3 * $r, 6, 6, imagecolorallocate( $im, 60, 60, 60 ) );
			imagefilledellipse( $im, $cx, $ball_top + $r, $r * 2, $r * 2, $wood );
			$neck_y = $ball_top + 2 * $r; $sh_y = $neck_y + (int) ( $H * 0.04 ); $half = (int) ( $W * 0.18 );
			imagefilledpolygon( $im, [ $cx - 4, $neck_y, $cx + 4, $neck_y, $cx + $half, $sh_y, $cx + $half, $sh_y + 10, $cx - $half, $sh_y + 10, $cx - $half, $sh_y ], $wood );
			// garment body
			$body_h = (int) ( $H * ( $shape === 'set' ? 0.72 : ( $shape === 'jacket' ? 0.5 : 0.42 ) ) );
			$body_w = (int) ( $W * ( $shape === 'wide' ? 0.30 : 0.22 ) );
			$sleeve = (int) ( $W * ( $shape === 'wide' ? 0.14 : 0.08 ) );
			$g_top = $sh_y; $g_bot = $g_top + $body_h;
			$x0 = $cx - $body_w - $sleeve; $x1 = $cx + $body_w + $sleeve;
			if ( ! empty( $opt['shade'] ) ) { // light garment: slight gradient so it is not flat
				for ( $y = $g_top; $y < $g_bot; $y++ ) { $v = (int) ( $opt['shade'] * ( $y - $g_top ) / $body_h ); $cc = imagecolorallocate( $im, $col[0] - $v, $col[1] - $v, $col[2] - $v ); imageline( $im, $cx - $body_w, $y, $cx + $body_w, $y, $cc ); }
			} else imagefilledrectangle( $im, $cx - $body_w, $g_top, $cx + $body_w, $g_bot, $c );
			$sl_h = (int) ( $body_h * 0.45 );
			imagefilledpolygon( $im, [ $cx - $body_w, $g_top, $x0, $g_top + 40, $x0, $g_top + $sl_h, $cx - $body_w, $g_top + $sl_h ], $c );
			imagefilledpolygon( $im, [ $cx + $body_w, $g_top, $x1, $g_top + 40, $x1, $g_top + $sl_h, $cx + $body_w, $g_top + $sl_h ], $c );
			if ( $shape === 'set' ) imagefilledrectangle( $im, $cx - (int) ( $body_w * 0.7 ), $g_bot - (int) ( $body_h * 0.45 ), $cx + (int) ( $body_w * 0.7 ), $g_bot, imagecolorallocate( $im, 40, 40, 90 ) );
			$truth = [ 'ball_top' => $ball_top, 'bbox' => [ min( $x0, $cx - $half ), $ball_top, max( $x1, $cx + $half ) + 1, $g_bot + 1 ] ];
			if ( ! empty( $opt['flags'] ) ) $truth['flags_expected'] = $opt['flags'];
			if ( ! empty( $opt['opts'] ) ) $truth['opts'] = $opt['opts'];
			if ( ! empty( $opt['skip_ball'] ) ) $truth['skip_ball'] = true; // ball smaller than the output criterion: anchor cannot be exact by definition
		} elseif ( $shape === 'mannequin' ) { // head + shoulders: wide dark shape at the top, no ball
			imagefilledellipse( $im, $cx, (int) ( $H * 0.2 ), (int) ( $W * 0.2 ), (int) ( $W * 0.24 ), $c );
			imagefilledrectangle( $im, $cx - (int) ( $W * 0.25 ), (int) ( $H * 0.32 ), $cx + (int) ( $W * 0.25 ), (int) ( $H * 0.9 ), $c );
			$truth = [ 'refuse' => true ];
		} else { // shoe: low, flat, dark
			imagefilledellipse( $im, $cx, (int) ( $H * 0.6 ), (int) ( $W * 0.6 ), (int) ( $H * 0.2 ), $c );
			$truth = [ 'refuse' => true ];
		}
		$file = "$dir/$name." . ( $png ? 'png' : 'jpg' );
		if ( $png ) imagepng( $im, $file ); else imagejpeg( $im, $file, 95 );
		imagedestroy( $im );
		$set[ $file ] = $truth;
	}
	return $set;
}
