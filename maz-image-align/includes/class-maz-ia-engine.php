<?php
/**
 * Mazyoud Image Align — image engine.
 *
 * Pure PHP, no WordPress dependencies (tests/run.php loads this file directly from the CLI).
 * Everything that touches the pixels lives here; everything that touches WordPress lives in
 * class-maz-ia-files.php / class-maz-ia-admin.php.
 *
 * Pipeline (process()):
 *   1. Probe + memory check            → clear error instead of a white screen.
 *   2. Load, honour EXIF orientation, convert to sRGB.
 *   3. Build a small RGBA "analysis" copy (≤ analysis_max px on the long side).
 *   4. analyze_rgba(): background from a border ring with outlier rejection, colour-distance
 *      subject mask, hanger-ball anchor with a confidence grade, plausibility flags.
 *   5. Map the bbox back to full resolution with a safety margin; refine the ball row at full res.
 *   6. Geometry: scale = min(fill, side_min, bottom_min) capped by max_upscale.
 *   7. Render: crop (plus any surrounding background that fits inside the margins), resample,
 *      paste on a canvas filled with the sampled background, one JPEG encode.
 */

if ( ! class_exists( 'Maz_IA_Engine' ) ) :

final class Maz_IA_Engine {

	/** Max per-channel distance from the ring median for a ring pixel to count as background. */
	const BG_OUTLIER = 12;

	/** Default geometry / detection settings (the WP layer stores these in the option). */
	public static function defaults() {
		return [
			'canvas_w'       => 2194,
			'canvas_h'       => 2560,
			'top_margin'     => 50,   // px from top edge to top of hanger ball
			'bottom_min'     => 60,   // px always kept free under the hem
			'side_min'       => 60,   // px always kept free left/right of the garment
			'color_delta'    => 12,   // subject = max per-channel distance from bg ≥ this
			'ball_min_pct'   => 2,    // min contiguous dark run width (% of width) to count as the ball
			'ball_max_pct'   => 14,   // wider than this at the top = not a ball (shoulders, shoe…)
			'ball_threshold' => 250,  // pixels brighter than this are never ball (the ball is found by colour distance, like the garment)
			'ball_band_pct'  => 70,   // ball must sit inside the central N % of the width
			'min_dark_px'    => 3,    // subject px per row/col needed (noise filter, full-res px)
			'edge_pad'       => 4,    // ignore pixels this close to the source border (full-res px)
			'ring_pct'       => 2,    // background sampled from this border band (% of short side)
			'max_upscale'    => 125,  // % — never enlarge the subject beyond this
			'analysis_max'   => 700,  // long side of the analysis copy (speed/memory bound)
			'keep_hook'      => 1,    // keep what is above the ball (hook) inside the top margin
			'require_ball'   => 1,    // refuse when no hanger ball is detected
			'quality'        => 92,
		];
	}

	/** Garment types → fill % of the usable height. Order = dropdown order. */
	public static function default_types() {
		return [
			'set'     => [ 'label' => 'Set (2–3 pieces)',       'fill' => 96 ],
			'robe'    => [ 'label' => 'Robe / dress / jumpsuit', 'fill' => 93 ],
			'coat'    => [ 'label' => 'Coat / long jacket',      'fill' => 93 ],
			'jacket'  => [ 'label' => 'Jacket / hoodie',         'fill' => 88 ],
			'pants'   => [ 'label' => 'Jeans / trousers',        'fill' => 85 ],
			'tshirt'  => [ 'label' => 'T-shirt / top / shirt',   'fill' => 78 ],
			'default' => [ 'label' => 'Default',                 'fill' => 88 ],
		];
	}

	public static function available() {
		if ( class_exists( 'Imagick' ) ) return 'imagick';
		if ( function_exists( 'imagecreatetruecolor' ) ) return 'gd';
		return false;
	}

	public static function error( $code, $msg ) { return [ 'ok' => false, 'code' => $code, 'error' => $msg ]; }

	public static function usable_height( $o ) { return (int) $o['canvas_h'] - (int) $o['top_margin'] - (int) $o['bottom_min']; }

	/** Target garment height in px for a fill percentage. */
	public static function fill_height( $fill, $o ) {
		return max( 50, (int) round( self::usable_height( $o ) * max( 10, min( 100, (int) $fill ) ) / 100 ) );
	}

	/** Bytes of PHP memory the pipeline needs for a W×H source (conservative, both engines). */
	public static function memory_needed( $w, $h, $o ) {
		$am = max( 100, (int) $o['analysis_max'] );
		$f  = max( 1, max( $w, $h ) / $am );
		$px = $w * $h;
		$analysis = (int) ( $px / ( $f * $f ) ) * 9;              // small copy + RGBA string
		return (int) ( $px * 5 * 2 + $o['canvas_w'] * $o['canvas_h'] * 5 + $analysis + 12 * 1024 * 1024 );
	}

	public static function memory_limit_bytes() {
		$v = trim( (string) ini_get( 'memory_limit' ) );
		if ( $v === '' || $v === '-1' ) return PHP_INT_MAX;
		$n = (int) $v; $u = strtolower( substr( $v, -1 ) );
		if ( $u === 'g' ) $n *= 1024 * 1024 * 1024; elseif ( $u === 'm' ) $n *= 1024 * 1024; elseif ( $u === 'k' ) $n *= 1024;
		return $n;
	}

	/** true, or an error array. */
	public static function memory_check( $w, $h, $o ) {
		$need = self::memory_needed( $w, $h, $o );
		$free = self::memory_limit_bytes() - memory_get_usage( true );
		if ( $free < $need ) {
			return self::error( 'memory', sprintf( 'Not enough PHP memory: image %d×%d needs ~%d MB, %d MB available (memory_limit %s).',
				$w, $h, (int) ceil( $need / 1048576 ), (int) max( 0, floor( $free / 1048576 ) ), ini_get( 'memory_limit' ) ) );
		}
		return true;
	}

	/* ====================================================================== process */

	/**
	 * @param string $src   readable source image
	 * @param string $dst   path to write the JPEG to (caller owns the temp file)
	 * @param array  $o     settings (see defaults()) plus 'fill' (percent) and optional 'engine' ('gd'|'imagick')
	 * @return array ok=>true + geometry/diagnostics, or ok=>false + code/error
	 */
	public static function process( $src, $dst, $o ) {
		$t0 = microtime( true );
		$o  = array_merge( self::defaults(), $o );
		$o['fill'] = isset( $o['fill'] ) ? (int) $o['fill'] : 88;

		if ( self::usable_height( $o ) < 100 ) return self::error( 'settings', 'Settings: margins leave less than 100 px of usable canvas height' );
		if ( $o['canvas_w'] - 2 * $o['side_min'] < 100 ) return self::error( 'settings', 'Settings: side margins leave less than 100 px of usable canvas width' );

		$engine = ! empty( $o['engine'] ) ? $o['engine'] : self::available();
		if ( ! $engine ) return self::error( 'engine', 'No image library available (install php-imagick or php-gd)' );
		if ( ! is_readable( $src ) ) return self::error( 'read', 'Source file is not readable' );

		$info = @getimagesize( $src );
		if ( ! $info || $info[0] < 10 || $info[1] < 10 ) return self::error( 'read', 'Not a readable raster image' );
		$mem = self::memory_check( $info[0], $info[1], $o );
		if ( $mem !== true ) return $mem;

		$r = $engine === 'imagick' ? self::run_imagick( $src, $dst, $o, $info ) : self::run_gd( $src, $dst, $o, $info );
		if ( $r['ok'] ) {
			$r['engine'] = $engine;
			$r['ms']     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		}
		return $r;
	}

	/* ------------------------------------------------------------ shared steps */

	/** Analysis dimensions for a W×H source. Returns [aw, ah, fx, fy]. */
	private static function analysis_dims( $w, $h, $o ) {
		$am = max( 100, (int) $o['analysis_max'] );
		$f  = max( 1.0, max( $w, $h ) / $am );
		$aw = max( 1, (int) round( $w / $f ) ); $ah = max( 1, (int) round( $h / $f ) );
		return [ $aw, $ah, $w / $aw, $h / $ah ];
	}

	/**
	 * Detect background, subject bbox and hanger ball in an RGBA byte buffer (analysis resolution).
	 * $f = full-res px per analysis px (used to scale the full-res thresholds).
	 */
	public static function analyze_rgba( $buf, $w, $h, $o, $f = 1.0 ) {
		$o = array_merge( self::defaults(), $o );
		if ( strlen( $buf ) < $w * $h * 4 ) return self::error( 'buffer', 'Analysis buffer too short' );
		$flags = [];

		/* ---- background: border ring, per-channel median, outlier rejection ---- */
		$ring = max( 2, (int) round( min( $w, $h ) * $o['ring_pct'] / 100 ) );
		$R = []; $G = []; $B = [];
		for ( $y = 0; $y < $h; $y++ ) {
			$edge_row = ( $y < $ring || $y >= $h - $ring );
			for ( $x = 0; $x < $w; $x++ ) {
				if ( ! $edge_row && $x === $ring ) { $x = $w - $ring; if ( $x >= $w ) break; }
				$i = ( $y * $w + $x ) * 4;
				if ( ord( $buf[ $i + 3 ] ) < 200 ) continue; // transparent: not a colour sample
				$R[] = ord( $buf[ $i ] ); $G[] = ord( $buf[ $i + 1 ] ); $B[] = ord( $buf[ $i + 2 ] );
			}
		}
		$n_ring = count( $R );
		if ( $n_ring < 16 ) { $bg = [ 247, 247, 247 ]; $flags[] = 'no_bg_ring'; $rej = 0; }
		else {
			$m = [ self::median( $R ), self::median( $G ), self::median( $B ) ];
			$R2 = []; $G2 = []; $B2 = [];
			for ( $k = 0; $k < $n_ring; $k++ ) {
				if ( max( abs( $R[ $k ] - $m[0] ), abs( $G[ $k ] - $m[1] ), abs( $B[ $k ] - $m[2] ) ) <= self::BG_OUTLIER ) { $R2[] = $R[ $k ]; $G2[] = $G[ $k ]; $B2[] = $B[ $k ]; }
			}
			$rej = 100 - (int) round( 100 * count( $R2 ) / $n_ring );
			$bg  = count( $R2 ) ? [ self::median( $R2 ), self::median( $G2 ), self::median( $B2 ) ] : $m;
			if ( $rej > 35 ) $flags[] = 'bg_noisy';
		}
		unset( $R, $G, $B, $R2, $G2, $B2 );

		/* ---- subject mask + ball candidates ---- */
		$delta  = (int) $o['color_delta'];
		$tb     = (int) $o['ball_threshold'];
		$pad    = max( 1, (int) ceil( $o['edge_pad'] / $f ) );
		$min    = max( 1, (int) round( $o['min_dark_px'] / $f ) );
		$band   = max( 10, min( 100, (int) $o['ball_band_pct'] ) ) / 200; // half-width as fraction
		$bx0    = (int) floor( $w * ( 0.5 - $band ) ); $bx1 = (int) ceil( $w * ( 0.5 + $band ) ) - 1;
		$bwmin  = max( 3, (int) round( $w * $o['ball_min_pct'] / 100 ) );
		$bwmax  = max( $bwmin + 1, (int) round( $w * $o['ball_max_pct'] / 100 ) );
		$rows   = array_fill( 0, $h, 0 ); $cols = array_fill( 0, $w, 0 );
		$runs   = []; // y => [width, x0, x1] of the widest dark run inside the band
		$bgr = $bg[0]; $bgg = $bg[1]; $bgb = $bg[2];

		for ( $y = $pad; $y < $h - $pad; $y++ ) {
			$base = $y * $w * 4; $run = 0; $best = 0; $best_x1 = 0; $rc = 0;
			for ( $x = $pad; $x < $w - $pad; $x++ ) {
				$i = $base + $x * 4;
				$a = ord( $buf[ $i + 3 ] );
				$r = ord( $buf[ $i ] ); $g = ord( $buf[ $i + 1 ] ); $b = ord( $buf[ $i + 2 ] );
				$d = max( abs( $r - $bgr ), abs( $g - $bgg ), abs( $b - $bgb ) );
				$subj = ( $a >= 128 && $d >= $delta );
				if ( $subj ) { $rc++; $cols[ $x ]++; }
				if ( $x >= $bx0 && $x <= $bx1 ) {
					if ( $subj && ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) < $tb ) { $run++; if ( $run > $best ) { $best = $run; $best_x1 = $x; } }
					else $run = 0;
				}
			}
			$rows[ $y ] = $rc;
			if ( $best >= $bwmin ) $runs[ $y ] = [ $best, $best_x1 - $best + 1, $best_x1 ];
		}

		$y_first = null; $y1 = null; $x0 = null; $x1 = null;
		for ( $y = 0; $y < $h; $y++ ) if ( $rows[ $y ] > $min ) { $y_first = $y; break; }
		for ( $y = $h - 1; $y >= 0; $y-- ) if ( $rows[ $y ] > $min ) { $y1 = $y + 1; break; }
		for ( $x = 0; $x < $w; $x++ ) if ( $cols[ $x ] > $min ) { $x0 = $x; break; }
		for ( $x = $w - 1; $x >= 0; $x-- ) if ( $cols[ $x ] > $min ) { $x1 = $x + 1; break; }
		if ( $y_first === null || $x0 === null ) return self::error( 'no_subject', 'No subject detected (image is uniform or colour delta too high)' );

		/* ---- hanger ball: topmost dark run that keeps its width for a few rows (a hook wire does not) ---- */
		$ball = [ 'y' => null, 'x0' => null, 'x1' => null, 'width' => 0, 'confidence' => 'none', 'reason' => 'no dark run of ball width in the centre band' ];
		if ( $runs ) {
			ksort( $runs );
			$K = max( 3, $bwmin * 4 );
			$first = null; $skip_until = -1;
			foreach ( $runs as $by => $cand ) {
				if ( $by <= $skip_until ) continue;
				[ $bw, $rx0, $rx1 ] = $cand;
				$persist = 0; $maxw = $bw; $cx0 = $rx0; $cx1 = $rx1;
				$Kw = max( 3, $bw ); // width is judged on the top rows only (below the ball come the hanger shoulders)
				for ( $y = $by; $y < min( $h, $by + $K ); $y++ ) {
					if ( ! isset( $runs[ $y ] ) || $runs[ $y ][2] < $cx0 || $runs[ $y ][1] > $cx1 ) break; // gone or not overlapping
					$persist++; $cx0 = min( $cx0, $runs[ $y ][1] ); $cx1 = max( $cx1, $runs[ $y ][2] );
					if ( $y < $by + $Kw ) $maxw = max( $maxw, $runs[ $y ][0] );
				}
				$c = [ 'y' => $by, 'x0' => $cx0, 'x1' => $cx1, 'width' => $maxw, 'persist' => $persist ];
				if ( $maxw > $bwmax ) { // shoulders / mannequin / shoe: wide right from the top
					if ( $first === null ) { $first = $c; $first['confidence'] = 'none'; $first['reason'] = sprintf( 'top dark shape is %d%% of the width — too wide for a hanger ball', (int) round( 100 * $maxw / $w ) ); }
					break;
				}
				if ( $persist >= max( 2, (int) round( $bw * 0.5 ) ) ) { $first = $c; $first['confidence'] = 'high'; $first['reason'] = 'round dark shape at the top of the subject'; break; }
				if ( $first === null ) { $first = $c; $first['confidence'] = 'medium'; $first['reason'] = 'dark shape found but it is very flat'; }
				$skip_until = $by + max( 1, $persist );
			}
			if ( $first ) $ball = array_merge( $ball, $first );
		}

		if ( $ball['confidence'] === 'none' ) {
			if ( ! empty( $o['require_ball'] ) ) return self::error( 'no_ball', 'No hanger ball detected (' . $ball['reason'] . '). Not a hanger photo? Mark it "Not a hanger photo" or disable "Require ball" in settings.' );
			$y0 = $y_first; $flags[] = 'no_ball_fallback';
		} else {
			$y0 = $ball['y'];
			if ( $y_first < $y0 - 0.2 * $h ) $flags[] = 'content_above_ball';
		}
		if ( $y1 <= $y0 || $x1 <= $x0 ) return self::error( 'no_subject', 'Subject area is empty' );

		/* ---- plausibility ---- */
		$bh = $y1 - $y0; $bwid = $x1 - $x0;
		if ( $x0 <= $pad || $x1 >= $w - $pad || $y1 >= $h - $pad || $y0 <= $pad ) $flags[] = 'touches_edge';
		if ( $bh < 0.35 * $h ) $flags[] = 'bbox_small';
		if ( $bh > 0.98 * $h ) $flags[] = 'bbox_large';
		$asp = $bwid / max( 1, $bh );
		if ( $asp < 0.25 || $asp > 2.0 ) $flags[] = 'aspect_odd';
		if ( $ball['confidence'] === 'medium' ) $flags[] = 'ball_medium';

		return [
			'ok' => true, 'bg' => $bg, 'bg_rejected_pct' => $rej, 'x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1,
			'y_first' => $y_first, 'ball' => $ball, 'flags' => $flags, 'ball_wmin' => $bwmin,
		];
	}

	/**
	 * Geometry from a full-res bbox. Returns dest/src rectangles and flags.
	 */
	public static function geometry( $bbox, $W, $H, $o ) {
		[ $x0, $y0, $x1, $y1 ] = $bbox;
		$bw = max( 1, $x1 - $x0 ); $bh = max( 1, $y1 - $y0 );
		$flags  = [];
		$usable = self::usable_height( $o );
		$fill_h = self::fill_height( $o['fill'], $o );
		$scale  = min( $fill_h / $bh, ( $o['canvas_w'] - 2 * $o['side_min'] ) / $bw, $usable / $bh );
		$maxup  = max( 100, (int) $o['max_upscale'] ) / 100;
		if ( $scale > $maxup ) { $scale = $maxup; $flags[] = 'upscale_capped'; }
		if ( $scale * $bh < $fill_h - 1 && $scale < $maxup - 1e-9 ) $flags[] = 'width_limited';

		$nw = max( 1, (int) round( $bw * $scale ) ); $nh = max( 1, (int) round( $bh * $scale ) );
		$dx = (int) round( ( $o['canvas_w'] - $nw ) / 2 ); $dy = (int) $o['top_margin'];

		// What sits above the ball (hanger hook) is kept when it fits inside the top margin.
		$et = empty( $o['keep_hook'] ) ? min( $y0, (int) ceil( 6 / $scale ) ) : min( $y0, (int) floor( $dy / $scale ) ); // keep_hook off: still a few px above the ball
		$el = 0; $er = 0; $eb = 0; // sides/bottom stay clean canvas background (no floor shadow, no props)
		$ET = (int) round( $et * $scale ); $EL = (int) round( $el * $scale );
		$ER = (int) round( $er * $scale ); $EB = (int) round( $eb * $scale );
		$ET = min( $ET, $dy ); $EL = min( $EL, $dx ); $ER = min( $ER, $o['canvas_w'] - $dx - $nw ); $EB = min( $EB, $o['canvas_h'] - $dy - $nh );

		return [
			'scale' => $scale, 'flags' => $flags, 'subject' => [ 'x' => $dx, 'y' => $dy, 'w' => $nw, 'h' => $nh ],
			'src'  => [ 'x' => $x0 - $el, 'y' => $y0 - $et, 'w' => $bw + $el + $er, 'h' => $bh + $et + $eb ],
			'dst'  => [ 'x' => $dx - $EL, 'y' => $dy - $ET, 'w' => $nw + $EL + $ER, 'h' => $nh + $ET + $EB ],
		];
	}

	/**
	 * Map analysis results to full resolution (with safety margin) and refine the ball row.
	 * $px_rows: callable(x, y, w, h) → RGB bytes (3 per pixel) of that full-res region, transparent = background.
	 */
	private static function to_full_res( $an, $W, $H, $fx, $fy, $o, $px_rows, $need = 0 ) {
		$s  = (int) ceil( max( $fx, $fy ) ) + 1;
		$x0 = max( 0, (int) floor( $an['x0'] * $fx ) - $s );
		$x1 = min( $W, (int) ceil( ( $an['x1'] ) * $fx ) + $s );
		$y1 = min( $H, (int) ceil( ( $an['y1'] ) * $fy ) + $s );
		$y0 = max( 0, (int) floor( $an['y0'] * $fy ) );
		$ball_y = null;
		if ( $an['ball']['confidence'] !== 'none' ) {
			$b   = $an['ball'];
			$rx0 = max( 0, (int) floor( $b['x0'] * $fx ) - $s ); $rx1 = min( $W - 1, (int) ceil( ( $b['x1'] + 1 ) * $fx ) + $s );
			$ry0 = max( 0, (int) floor( ( $b['y'] - 1 ) * $fy ) ); $ry1 = min( $H - 1, (int) ceil( ( $b['y'] + 2 ) * $fy ) );
			$rw  = $rx1 - $rx0 + 1; $rh = $ry1 - $ry0 + 1;
			if ( $need < 4 ) $need = max( 4, (int) round( $W * $o['ball_min_pct'] / 100 ) );
			$px   = $px_rows( $rx0, $ry0, $rw, $rh );
			if ( is_string( $px ) && strlen( $px ) >= $rw * $rh * 3 ) {
				$tb = (int) $o['ball_threshold']; $delta = (int) $o['color_delta']; [ $bgr, $bgg, $bgb ] = $an['bg'];
				for ( $y = 0; $y < $rh && $ball_y === null; $y++ ) {
					$run = 0; $base = $y * $rw * 3;
					for ( $x = 0; $x < $rw; $x++ ) {
						$i = $base + $x * 3; $r = ord( $px[ $i ] ); $g = ord( $px[ $i + 1 ] ); $b = ord( $px[ $i + 2 ] );
						$is = max( abs( $r - $bgr ), abs( $g - $bgg ), abs( $b - $bgb ) ) >= $delta && ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) < $tb;
						if ( $is ) { if ( ++$run >= $need ) { $ball_y = $ry0 + $y; break; } } else $run = 0;
					}
				}
			}
			if ( $ball_y !== null ) $y0 = $ball_y; else $y0 = max( 0, (int) floor( $b['y'] * $fy ) );
		} else {
			$y0 = max( 0, $y0 - $s );
		}
		return [ $x0, $y0, $x1, $y1, $ball_y ];
	}

	/**
	 * Full-res bbox + geometry. Two passes: the "top of the ball" is the first row whose dark run is
	 * ball_min_pct of the OUTPUT width, so the anchor is identical whatever the source scale.
	 */
	private static function place( $an, $W, $H, $fx, $fy, $o, $px_rows ) {
		[ $x0, $y0, $x1, $y1, $ball_y ] = self::to_full_res( $an, $W, $H, $fx, $fy, $o, $px_rows );
		$geo = self::geometry( [ $x0, $y0, $x1, $y1 ], $W, $H, $o );
		if ( $ball_y !== null ) {
			$need    = max( 4, (int) round( $o['canvas_w'] * $o['ball_min_pct'] / 100 / $geo['scale'] ) );
			$ball_px = $an['ball']['width'] * $fx; // ball diameter in source px
			if ( $need < 0.8 * $ball_px ) { // only when the ball is clearly wider than the criterion (tiny sources keep pass 1)
				$r2 = self::to_full_res( $an, $W, $H, $fx, $fy, $o, $px_rows, $need );
				if ( $r2[4] !== null ) { [ $x0, $y0, $x1, $y1, $ball_y ] = $r2; $geo = self::geometry( [ $x0, $y0, $x1, $y1 ], $W, $H, $o ); }
			}
		}
		return [ [ $x0, $y0, $x1, $y1 ], $geo, $ball_y ];
	}

	private static function result( $an, $bbox, $geo, $W, $H, $ball_y, $o ) {
		return [
			'ok' => true, 'src_size' => [ $W, $H ], 'bg' => $an['bg'], 'bg_rejected_pct' => $an['bg_rejected_pct'],
			'bbox' => $bbox, 'ball' => [ 'y' => $ball_y, 'confidence' => $an['ball']['confidence'], 'reason' => $an['ball']['reason'] ],
			'scale' => round( $geo['scale'], 4 ), 'subject' => $geo['subject'], 'fill' => (int) $o['fill'],
			'flags' => array_values( array_unique( array_merge( $an['flags'], $geo['flags'] ) ) ),
		];
	}

	/** Human-readable flag labels (shared by UI and tests). */
	public static function flag_labels() {
		return [
			'touches_edge'       => 'Subject touches the source border — original framing too tight',
			'bbox_small'         => 'Subject is less than 35 % of the frame — check detection',
			'bbox_large'         => 'Subject fills more than 98 % of the frame — check detection',
			'aspect_odd'         => 'Unusual subject proportions',
			'ball_medium'        => 'Hanger ball detected with medium confidence',
			'no_ball_fallback'   => 'No hanger ball — aligned on the first dark row',
			'content_above_ball' => 'Something sits well above the hanger ball',
			'bg_noisy'           => 'Background is uneven (shadow, prop or sticker near the border)',
			'no_bg_ring'         => 'Transparent border — background colour defaulted',
			'upscale_capped'     => 'Source too small for this fill — enlargement capped',
			'width_limited'      => 'Garment is wide — height reduced to keep the side margins',
		];
	}

	/* ====================================================================== GD */

	private static function run_gd( $src, $dst, $o, $info ) {
		$img = self::gd_load( $src, $info[2] );
		if ( ! $img ) return self::error( 'read', 'GD cannot decode this file' );
		if ( ! imageistruecolor( $img ) ) imagepalettetotruecolor( $img );
		$img = self::gd_orient( $img, $src, $info[2] );
		$W = imagesx( $img ); $H = imagesy( $img );

		[ $aw, $ah, $fx, $fy ] = self::analysis_dims( $W, $H, $o );
		if ( $aw < $W ) {
			imagealphablending( $img, false ); imagesavealpha( $img, true );
			$a = imagescale( $img, $aw, $ah, IMG_BILINEAR_FIXED );
			if ( ! $a ) { imagedestroy( $img ); return self::error( 'gd', 'GD cannot build the analysis copy' ); }
		} else { $a = $img; }
		$buf = self::gd_rgba( $a, $aw, $ah );
		if ( $a !== $img ) imagedestroy( $a );

		$an = self::analyze_rgba( $buf, $aw, $ah, $o, max( $fx, $fy ) );
		unset( $buf );
		if ( ! $an['ok'] ) { imagedestroy( $img ); return $an; }

		$bg = $an['bg'];
		$px_rows = function ( $x, $y, $w, $h ) use ( $img, $bg ) {
			$s = str_repeat( "\0", $w * $h * 3 );
			for ( $yy = 0; $yy < $h; $yy++ ) for ( $xx = 0; $xx < $w; $xx++ ) {
				$p = imagecolorat( $img, $x + $xx, $y + $yy ); $i = ( $yy * $w + $xx ) * 3;
				if ( ( ( $p >> 24 ) & 127 ) > 63 ) { $s[ $i ] = chr( $bg[0] ); $s[ $i + 1 ] = chr( $bg[1] ); $s[ $i + 2 ] = chr( $bg[2] ); } // transparent = background
				else { $s[ $i ] = chr( ( $p >> 16 ) & 255 ); $s[ $i + 1 ] = chr( ( $p >> 8 ) & 255 ); $s[ $i + 2 ] = chr( $p & 255 ); }
			}
			return $s;
		};
		[ $bbox, $geo, $ball_y ] = self::place( $an, $W, $H, $fx, $fy, $o, $px_rows );

		$canvas = imagecreatetruecolor( (int) $o['canvas_w'], (int) $o['canvas_h'] );
		if ( ! $canvas ) { imagedestroy( $img ); return self::error( 'memory', 'GD cannot allocate the output canvas' ); }
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, $an['bg'][0], $an['bg'][1], $an['bg'][2] ) );
		imagealphablending( $canvas, true );
		imagealphablending( $img, true );
		$s = $geo['src']; $d = $geo['dst'];
		imagecopyresampled( $canvas, $img, $d['x'], $d['y'], $s['x'], $s['y'], $d['w'], $d['h'], $s['w'], $s['h'] );
		imagedestroy( $img );
		imageinterlace( $canvas, 1 );
		$ok = imagejpeg( $canvas, $dst, (int) $o['quality'] );
		imagedestroy( $canvas );
		if ( ! $ok ) return self::error( 'write', 'GD could not write the output file' );
		return self::result( $an, $bbox, $geo, $W, $H, $ball_y, $o );
	}

	private static function gd_load( $src, $type ) {
		switch ( $type ) {
			case IMAGETYPE_JPEG: return @imagecreatefromjpeg( $src );
			case IMAGETYPE_PNG:  return @imagecreatefrompng( $src );
			case IMAGETYPE_GIF:  return @imagecreatefromgif( $src );
			case IMAGETYPE_WEBP: return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $src ) : false;
			case IMAGETYPE_BMP:  return function_exists( 'imagecreatefrombmp' ) ? @imagecreatefrombmp( $src ) : false;
			default:
				if ( defined( 'IMAGETYPE_AVIF' ) && $type === IMAGETYPE_AVIF && function_exists( 'imagecreatefromavif' ) ) return @imagecreatefromavif( $src );
				$data = @file_get_contents( $src ); $im = $data ? @imagecreatefromstring( $data ) : false; unset( $data ); return $im;
		}
	}

	/** Apply EXIF orientation (JPEG only) so the scan sees the picture the way a browser shows it. */
	private static function gd_orient( $img, $src, $type ) {
		if ( $type !== IMAGETYPE_JPEG || ! function_exists( 'exif_read_data' ) ) return $img;
		$exif = @exif_read_data( $src );
		$or   = isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
		if ( $or < 2 || $or > 8 ) return $img;
		imagealphablending( $img, false ); imagesavealpha( $img, true );
		$bg = imagecolorallocatealpha( $img, 0, 0, 0, 127 );
		$rot = function ( $im, $deg ) use ( $bg ) { $r = imagerotate( $im, $deg, $bg ); if ( $r ) { imagedestroy( $im ); imagesavealpha( $r, true ); return $r; } return $im; };
		switch ( $or ) {
			case 2: imageflip( $img, IMG_FLIP_HORIZONTAL ); break;
			case 3: $img = $rot( $img, 180 ); break;
			case 4: imageflip( $img, IMG_FLIP_VERTICAL ); break;
			case 5: $img = $rot( $img, -90 ); imageflip( $img, IMG_FLIP_HORIZONTAL ); break;
			case 6: $img = $rot( $img, -90 ); break;
			case 7: $img = $rot( $img, 90 ); imageflip( $img, IMG_FLIP_HORIZONTAL ); break;
			case 8: $img = $rot( $img, 90 ); break;
		}
		return $img;
	}

	/** RGBA byte string of a (small) GD image. */
	private static function gd_rgba( $im, $w, $h ) {
		$s = str_repeat( "\0", $w * $h * 4 );
		for ( $y = 0; $y < $h; $y++ ) { $row = $y * $w * 4; for ( $x = 0; $x < $w; $x++ ) {
			$p = imagecolorat( $im, $x, $y ); $i = $row + $x * 4;
			$s[ $i ] = chr( ( $p >> 16 ) & 255 ); $s[ $i + 1 ] = chr( ( $p >> 8 ) & 255 ); $s[ $i + 2 ] = chr( $p & 255 );
			$s[ $i + 3 ] = chr( 255 - min( 255, ( ( $p >> 24 ) & 127 ) * 2 ) );
		} }
		return $s;
	}

	/* ====================================================================== Imagick */

	private static function run_imagick( $src, $dst, $o, $info ) {
		try {
			$im = new Imagick();
			$im->readImage( $src );
			if ( $im->getNumberImages() > 1 ) { $im->setIteratorIndex( 0 ); $im = $im->getImage(); } // animated: first frame
			// EXIF orientation first, so the scan and the output share the browser's view.
			if ( method_exists( $im, 'autoOrient' ) ) { $im->autoOrient(); }
			else { self::im_orient_manual( $im ); }
			$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
			// Colour: convert to sRGB using the embedded profile if any (CMYK / AdobeRGB / gray), then drop profiles.
			if ( $im->getImageColorspace() !== Imagick::COLORSPACE_SRGB ) $im->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			$W = $im->getImageWidth(); $H = $im->getImageHeight();
			$has_alpha = $im->getImageAlphaChannel() ? true : false;

			[ $aw, $ah, $fx, $fy ] = self::analysis_dims( $W, $H, $o );
			$a = clone $im;
			if ( $aw < $W ) $a->scaleImage( $aw, $ah );
			$a->setImageDepth( 8 );
			if ( $has_alpha ) { $buf = self::im_bytes( $a->exportImagePixels( 0, 0, $aw, $ah, 'RGBA', Imagick::PIXEL_CHAR ) ); }
			else {
				$rgb = $a->exportImagePixels( 0, 0, $aw, $ah, 'RGB', Imagick::PIXEL_CHAR );
				$buf = str_repeat( "\0", $aw * $ah * 4 );
				for ( $i = 0, $j = 0, $L = count( $rgb ); $i < $L; $i += 3, $j += 4 ) { $buf[ $j ] = chr( $rgb[ $i ] ); $buf[ $j + 1 ] = chr( $rgb[ $i + 1 ] ); $buf[ $j + 2 ] = chr( $rgb[ $i + 2 ] ); $buf[ $j + 3 ] = "\xff"; }
				unset( $rgb );
			}
			$a->destroy();

			$an = self::analyze_rgba( $buf, $aw, $ah, $o, max( $fx, $fy ) );
			unset( $buf );
			if ( ! $an['ok'] ) { $im->destroy(); return $an; }

			// Flatten transparency onto the *sampled* background (not white) before rendering.
			if ( $has_alpha ) {
				$im->setImageBackgroundColor( new ImagickPixel( sprintf( 'rgb(%d,%d,%d)', $an['bg'][0], $an['bg'][1], $an['bg'][2] ) ) );
				$im = $im->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
			}

			$px_rows = function ( $x, $y, $w, $h ) use ( $im ) {
				$px = $im->exportImagePixels( $x, $y, $w, $h, 'RGB', Imagick::PIXEL_CHAR );
				return $px ? self::im_bytes( $px ) : '';
			};
			[ $bbox, $geo, $ball_y ] = self::place( $an, $W, $H, $fx, $fy, $o, $px_rows );
			$s = $geo['src']; $d = $geo['dst'];

			$im->cropImage( $s['w'], $s['h'], $s['x'], $s['y'] );
			$im->setImagePage( 0, 0, 0, 0 );
			$im->resizeImage( $d['w'], $d['h'], Imagick::FILTER_LANCZOS, 1 );

			$canvas = new Imagick();
			$canvas->newImage( (int) $o['canvas_w'], (int) $o['canvas_h'], new ImagickPixel( sprintf( 'rgb(%d,%d,%d)', $an['bg'][0], $an['bg'][1], $an['bg'][2] ) ) );
			$canvas->setImageColorspace( Imagick::COLORSPACE_SRGB );
			$canvas->compositeImage( $im, Imagick::COMPOSITE_OVER, $d['x'], $d['y'] );
			$im->destroy();
			$canvas->setImageFormat( 'jpeg' );
			$canvas->setImageCompressionQuality( (int) $o['quality'] );
			$canvas->setInterlaceScheme( Imagick::INTERLACE_JPEG );
			$canvas->stripImage(); // output is plain sRGB; ICC/EXIF dropped on purpose
			$ok = $canvas->writeImage( 'jpeg:' . $dst );
			$canvas->destroy();
			if ( ! $ok ) return self::error( 'write', 'Imagick could not write the output file' );
			return self::result( $an, $bbox, $geo, $W, $H, $ball_y, $o );
		} catch ( Exception $e ) {
			return self::error( 'imagick', 'Imagick: ' . $e->getMessage() );
		}
	}

	private static function im_orient_manual( $im ) {
		switch ( $im->getImageOrientation() ) {
			case Imagick::ORIENTATION_TOPRIGHT:     $im->flopImage(); break;
			case Imagick::ORIENTATION_BOTTOMRIGHT:  $im->rotateImage( '#000', 180 ); break;
			case Imagick::ORIENTATION_BOTTOMLEFT:   $im->flipImage(); break;
			case Imagick::ORIENTATION_LEFTTOP:      $im->rotateImage( '#000', 90 ); $im->flopImage(); break;
			case Imagick::ORIENTATION_RIGHTTOP:     $im->rotateImage( '#000', 90 ); break;
			case Imagick::ORIENTATION_RIGHTBOTTOM:  $im->rotateImage( '#000', -90 ); $im->flopImage(); break;
			case Imagick::ORIENTATION_LEFTBOTTOM:   $im->rotateImage( '#000', -90 ); break;
		}
	}

	private static function im_bytes( array $px ) {
		$out = '';
		foreach ( array_chunk( $px, 65536 ) as $chunk ) $out .= pack( 'C*', ...$chunk );
		return $out;
	}

	/* ====================================================================== helpers */

	public static function median( $a ) { sort( $a ); $n = count( $a ); return $n ? (int) $a[ (int) ( $n / 2 ) ] : 247; }

	public static function lum( $c ) { return (int) ( 0.299 * $c[0] + 0.587 * $c[1] + 0.114 * $c[2] ); }

	/** Settings that influence the output — hashed into the per-image history. */
	public static function settings_hash( $o, $types ) {
		$keys = array_keys( self::defaults() );
		$sub  = [];
		foreach ( $keys as $k ) if ( isset( $o[ $k ] ) ) $sub[ $k ] = $o[ $k ];
		return substr( md5( json_encode( [ $sub, $types ] ) ), 0, 10 );
	}
}

endif;
