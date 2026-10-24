<?php
/**
 * Mazyoud Image Align — settings (option storage, sanitising, settings page).
 */

if ( ! class_exists( 'Maz_IA_Settings' ) ) :

final class Maz_IA_Settings {

	const OPT       = 'maz_ia_settings';
	const OPT_TYPES = 'maz_ia_settings_types';
	const GROUP     = 'maz_ia';

	/** Plugin-level defaults = engine defaults + behaviour switches. */
	public static function defaults() {
		return array_merge( Maz_IA_Engine::defaults(), [
			'preview'    => 1,   // single-image buttons open a before/after preview first
			'sync_limit' => 4,   // bulk selections up to this size run immediately
			'batch_size' => 4,   // images per background run
		] );
	}

	/** Integer ranges for every numeric setting: [min, max]. */
	private static function ranges() {
		return [
			'canvas_w' => [ 200, 8000 ], 'canvas_h' => [ 200, 8000 ], 'top_margin' => [ 0, 2000 ], 'bottom_min' => [ 0, 2000 ], 'side_min' => [ 0, 2000 ],
			'color_delta' => [ 3, 120 ], 'ball_min_pct' => [ 1, 20 ], 'ball_max_pct' => [ 2, 60 ], 'ball_threshold' => [ 20, 250 ], 'ball_band_pct' => [ 10, 100 ],
			'min_dark_px' => [ 1, 100 ], 'edge_pad' => [ 0, 200 ], 'ring_pct' => [ 1, 10 ], 'max_upscale' => [ 100, 400 ], 'analysis_max' => [ 200, 2000 ],
			'quality' => [ 60, 100 ], 'sync_limit' => [ 0, 50 ], 'batch_size' => [ 1, 25 ],
		];
	}

	private static function bools() { return [ 'keep_hook', 'require_ball', 'preview' ]; }

	public static function opts() {
		$o = wp_parse_args( get_option( self::OPT, [] ), self::defaults() );
		if ( $o['quality'] < 92 ) $o['quality'] = max( 92, (int) $o['quality'] ); // never below the business rule
		return $o;
	}

	public static function types() {
		$saved = get_option( self::OPT_TYPES, [] );
		$t = Maz_IA_Engine::default_types();
		if ( is_array( $saved ) ) foreach ( $saved as $k => $v ) if ( isset( $t[ $k ], $v['fill'] ) ) $t[ $k ]['fill'] = max( 10, min( 100, (int) $v['fill'] ) );
		return $t;
	}

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
	}

	public static function menu() {
		add_options_page( 'Image Align', 'Image Align', 'manage_options', 'maz-image-align', [ __CLASS__, 'page' ] );
	}

	public static function register() {
		register_setting( self::GROUP, self::OPT,       [ 'type' => 'array', 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
		register_setting( self::GROUP, self::OPT_TYPES, [ 'type' => 'array', 'sanitize_callback' => [ __CLASS__, 'sanitize_types' ] ] );
	}

	public static function sanitize( $in ) {
		if ( ! current_user_can( 'manage_options' ) ) return get_option( self::OPT, [] );
		$in = is_array( $in ) ? $in : [];
		$out = [];
		foreach ( self::defaults() as $k => $d ) {
			if ( in_array( $k, self::bools(), true ) ) { $out[ $k ] = empty( $in[ $k ] ) ? 0 : 1; continue; }
			$r = self::ranges()[ $k ] ?? [ 1, PHP_INT_MAX ];
			$out[ $k ] = max( $r[0], min( $r[1], (int) ( $in[ $k ] ?? $d ) ) );
		}
		if ( $out['quality'] < 92 ) $out['quality'] = 92;
		if ( $out['top_margin'] + $out['bottom_min'] + 100 > $out['canvas_h'] ) { $out['top_margin'] = 50; $out['bottom_min'] = 60; add_settings_error( self::OPT, 'margins', 'Top + bottom margins left no room for the garment — reset to 50 / 60.' ); }
		if ( 2 * $out['side_min'] + 100 > $out['canvas_w'] ) { $out['side_min'] = 60; add_settings_error( self::OPT, 'side', 'Side margin too large — reset to 60.' ); }
		if ( $out['ball_max_pct'] <= $out['ball_min_pct'] ) $out['ball_max_pct'] = $out['ball_min_pct'] + 1;
		return $out;
	}

	public static function sanitize_types( $in ) {
		if ( ! current_user_can( 'manage_options' ) ) return get_option( self::OPT_TYPES, [] );
		$in = is_array( $in ) ? $in : [];
		$out = [];
		foreach ( Maz_IA_Engine::default_types() as $k => $v ) $out[ $k ] = [ 'fill' => max( 10, min( 100, (int) ( $in[ $k ]['fill'] ?? $v['fill'] ) ) ) ];
		return $out;
	}

	/* ------------------------------------------------------------ page */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$o = self::opts();
		$sections = [
			'Canvas & margins' => [
				'canvas_w'   => [ 'Canvas width (px)', 'Output is always exactly this size.' ],
				'canvas_h'   => [ 'Canvas height (px)', '6:7 ratio for the shop grid = 2194 × 2560.' ],
				'top_margin' => [ 'Top margin (px)', 'Distance from the top edge to the top of the hanger ball — identical on every image.' ],
				'bottom_min' => [ 'Bottom margin (px)', 'Always kept free under the hem.' ],
				'side_min'   => [ 'Side margin (px)', 'Always kept free left and right of the garment (wide garments are scaled down to respect it).' ],
			],
			'Detection' => [
				'color_delta'    => [ 'Colour delta (0–255)', 'A pixel belongs to the garment when any RGB channel differs from the background by at least this. Lower = catches lighter garments, higher = ignores more noise. Default 12.' ],
				'ring_pct'       => [ 'Background ring (% of short side)', 'Background colour is the outlier-free median of this border band.' ],
				'ball_threshold' => [ 'Hanger ball luminance (0–255)', 'Pixels darker than this may belong to the ball. Default 205.' ],
				'ball_min_pct'   => [ 'Ball min width (% of width)', 'Narrower dark runs (hook wire, threads) are ignored. Default 2.' ],
				'ball_max_pct'   => [ 'Ball max width (% of width)', 'A wider dark shape at the top is not a ball (mannequin shoulders, shoes) → refused. Default 14.' ],
				'ball_band_pct'  => [ 'Ball search band (% of width, centred)', 'Off-centre hangers need a wider band. Default 70.' ],
				'min_dark_px'    => [ 'Noise filter (px)', 'Subject pixels a row/column needs to count.' ],
				'edge_pad'       => [ 'Edge pad (px)', 'Pixels this close to the source border are ignored.' ],
				'analysis_max'   => [ 'Analysis size (px)', 'Long side of the reduced copy used for detection. Larger = slower, more precise. Default 700.' ],
			],
			'Quality & behaviour' => [
				'quality'     => [ 'JPEG quality', 'Minimum 92 (one encode per image, redo always starts from the pristine backup).' ],
				'max_upscale' => [ 'Max enlargement (%)', 'A small source is never enlarged beyond this; the image gets flagged instead. Default 125.' ],
				'sync_limit'  => [ 'Bulk: run immediately up to', 'Larger selections are processed in the background (Action Scheduler / WP-Cron).' ],
				'batch_size'  => [ 'Bulk: images per background run', 'Keep small on shared hosting.' ],
			],
		];
		$checks = [
			'preview'      => 'Show a before / after preview before applying (single-image buttons)',
			'keep_hook'    => 'Keep the hanger hook above the ball (what fits inside the top margin)',
			'require_ball' => 'Refuse images where no hanger ball is detected (protects mannequin and shoe photos)',
		];
		$engine = Maz_IA_Engine::available();
		$need = Maz_IA_Engine::memory_needed( $o['canvas_w'], $o['canvas_h'], $o );
		$limit = Maz_IA_Engine::memory_limit_bytes();
		settings_errors( self::OPT );
		?>
		<div class="wrap"><h1>Mazyoud Image Align</h1>
		<form method="post" action="options.php"><?php settings_fields( self::GROUP ); ?>
		<?php foreach ( $sections as $title => $fields ) : ?>
			<h2><?php echo esc_html( $title ); ?></h2>
			<table class="form-table" role="presentation">
			<?php foreach ( $fields as $k => $f ) : ?>
				<tr><th scope="row"><label for="maz_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $f[0] ); ?></label></th>
				<td><input type="number" id="maz_<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( self::OPT . '[' . $k . ']' ); ?>" value="<?php echo esc_attr( $o[ $k ] ); ?>" class="small-text">
				<p class="description"><?php echo esc_html( $f[1] ); ?></p></td></tr>
			<?php endforeach; ?>
			</table>
		<?php endforeach; ?>
			<table class="form-table" role="presentation">
			<?php foreach ( $checks as $k => $label ) : ?>
				<tr><th scope="row"></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT . '[' . $k . ']' ); ?>" value="1" <?php checked( ! empty( $o[ $k ] ) ); ?>> <?php echo esc_html( $label ); ?></label></td></tr>
			<?php endforeach; ?>
			<tr><th scope="row">Garment types — fill %</th><td>
				<p class="description">Share of the usable height (canvas − top − bottom = <strong><?php echo (int) Maz_IA_Engine::usable_height( $o ); ?> px</strong>) the garment occupies. Bigger % = more presence in the grid. Types are chosen by hand per image — nothing is auto-detected.</p>
				<table class="widefat striped" style="max-width:560px"><thead><tr><th>Type</th><th>Fill %</th><th>Garment height</th></tr></thead><tbody>
				<?php foreach ( self::types() as $k => $t ) : ?>
					<tr><td><?php echo esc_html( $t['label'] ); ?></td>
					<td><input type="number" min="10" max="100" name="<?php echo esc_attr( self::OPT_TYPES . '[' . $k . '][fill]' ); ?>" value="<?php echo esc_attr( (int) $t['fill'] ); ?>" class="small-text"> %</td>
					<td><?php echo (int) Maz_IA_Engine::fill_height( $t['fill'], $o ); ?> px</td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</td></tr>
			</table>
			<p><strong>Engine:</strong> <?php echo $engine ? esc_html( $engine === 'imagick' ? 'Imagick' : 'GD (works, ~1 s per image)' ) : '<span style="color:#c00">none — install php-imagick or php-gd</span>'; ?>
			&nbsp;·&nbsp; <strong>Memory:</strong> <?php echo esc_html( sprintf( '~%d MB needed per %d×%d image, memory_limit %s', (int) ceil( $need / 1048576 ), $o['canvas_w'], $o['canvas_h'], ini_get( 'memory_limit' ) ) ); ?>
			<?php if ( $limit !== PHP_INT_MAX && $limit < $need ) echo ' <span style="color:#c00">— too low, raise memory_limit or lower the canvas</span>'; ?>
			&nbsp;·&nbsp; <strong>Queue:</strong> <?php echo esc_html( Maz_IA_Queue::backend_label() ); ?>
			&nbsp;·&nbsp; <strong>Output:</strong> always JPEG, sRGB, progressive, metadata stripped; one pristine backup per image (<code>name-orig.ext</code>).</p>
			<?php submit_button(); ?>
		</form></div>
		<?php
	}
}

endif;
