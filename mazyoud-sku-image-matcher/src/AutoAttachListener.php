<?php
/**
 * Auto-attach on upload (add_attachment listener).
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

use Mazyoud\SkuImageMatcher\Matching\MatchContext;
use Mazyoud\SkuImageMatcher\Matching\MatcherInterface;
use Mazyoud\SkuImageMatcher\Matching\ParentProductSkuMatcher;
use Mazyoud\SkuImageMatcher\Matching\ProductIdMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * When the "Auto-attach on upload" setting is enabled, every newly uploaded
 * image is parsed and — if it resolves to a product — attached immediately.
 *
 * Single-file fast path: the full SKU set is never loaded. The filename's
 * prefix candidates are resolved in ONE IN() query against
 * wc_product_meta_lookup (longest match wins), with a product-ID existence
 * check only when the leading token is numeric. The whole handler is
 * wrapped in try/catch: a parse or DB failure can never break the media
 * upload itself. No snapshots are taken, but every action is logged.
 */
class AutoAttachListener {

	/**
	 * Constructor.
	 *
	 * @param FilenameParser $parser   Filename parser.
	 * @param SkuResolver    $resolver SKU resolver.
	 * @param Settings       $settings Settings accessor.
	 * @param Assigner       $assigner Assigner.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct(
		private FilenameParser $parser,
		private SkuResolver $resolver,
		private Settings $settings,
		private Assigner $assigner,
		private Logger $logger
	) {
	}

	/**
	 * Register the add_attachment hook. This is the plugin's single
	 * non-admin hook, registered only when the setting is enabled.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_attachment', array( $this, 'handle' ), 20, 1 );
	}

	/**
	 * Handle a new attachment. Never throws.
	 *
	 * @param int $attachment_id New attachment ID.
	 * @return void
	 */
	public function handle( $attachment_id ) {
		try {
			$this->process( (int) $attachment_id );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Auto-attach failed (upload unaffected).',
				array(
					'attachment_id' => (int) $attachment_id,
					'error'         => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Resolve and attach one new upload.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function process( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return;
		}

		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $attached_file ) {
			return;
		}

		$parsed = $this->parser->parse( $attached_file );
		if ( '' === $parsed->stem ) {
			return;
		}

		// WP duplicate-upload rename — never silently assign.
		if ( $this->parser->is_wp_duplicate_rename( $parsed, (string) $post->post_title ) ) {
			$this->logger->warning(
				'Auto-attach skipped: filename looks like a WordPress duplicate-upload rename.',
				array(
					'attachment_id' => $attachment_id,
					'file'          => $parsed->file,
					'title'         => (string) $post->post_title,
				)
			);

			return;
		}

		// Auto-attach during an active manual run: skip attachments already
		// present in the active run's plan (they will be handled by the run).
		if ( $this->in_active_run_plan( $attachment_id ) ) {
			$this->logger->info( 'Auto-attach skipped: attachment is part of the active run plan.', array( 'attachment_id' => $attachment_id ) );

			return;
		}

		// ONE IN() query over this file's candidates (products + variations).
		$grouped = $this->resolver->resolve_candidates_grouped(
			$parsed->all_candidates(),
			array( 'product', 'product_variation' )
		);

		$product_rows   = isset( $grouped['product'] ) ? $grouped['product'] : array();
		$variation_rows = isset( $grouped['product_variation'] ) ? $grouped['product_variation'] : array();

		$context = new MatchContext(
			$product_rows,
			function ( $product_id ) {
				return $this->resolver->product_exists( $product_id );
			}
		);

		$result = null;
		foreach ( $this->get_matchers() as $matcher ) {
			$result = $matcher->match( $parsed, $context );
			if ( null !== $result ) {
				break;
			}
		}

		if ( null === $result ) {
			$variation_hits = array();
			foreach ( $parsed->all_candidates() as $candidate ) {
				if ( isset( $variation_rows[ $candidate ] ) ) {
					$variation_hits[] = $candidate;
				}
			}

			if ( ! empty( $variation_hits ) ) {
				$this->logger->info(
					'Auto-attach: filename resolves to a variation SKU — planned for v2, not assigned.',
					array(
						'attachment_id' => $attachment_id,
						'file'          => $parsed->file,
						'sku'           => $variation_hits[0],
					)
				);
			} else {
				$this->logger->debug( 'Auto-attach: no matching SKU or product ID.', array( 'attachment_id' => $attachment_id, 'file' => $parsed->file ) );
			}

			return;
		}

		if ( $result->is_error() ) {
			$this->logger->error(
				'Auto-attach skipped: SKU exists on multiple products — never guessing.',
				array(
					'attachment_id' => $attachment_id,
					'file'          => $parsed->file,
					'sku'           => $result->identifier,
					'product_ids'   => $result->error_product_ids,
				)
			);

			return;
		}

		foreach ( $result->warnings as $warning ) {
			$this->logger->warning( 'Auto-attach: ' . $warning, array( 'attachment_id' => $attachment_id ) );
		}

		$mode = (string) $this->settings->get( 'existing_mode' );
		$this->assigner->attach_single( $attachment_id, $result, $mode );
	}

	/**
	 * Whether the attachment is part of the currently active run's plan.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function in_active_run_plan( $attachment_id ) {
		$active = (int) get_option( RunRepository::OPTION_LOCK, 0 );
		if ( $active <= 0 ) {
			return false;
		}

		$plan = get_option( RunRepository::plan_option( $active ), null );
		if ( ! is_array( $plan ) || empty( $plan['attachment_ids'] ) ) {
			return false;
		}

		return in_array( (int) $attachment_id, array_map( 'intval', (array) $plan['attachment_ids'] ), true );
	}

	/**
	 * Matcher pipeline (same `msim_matchers` filter as the bulk scan).
	 *
	 * @return MatcherInterface[]
	 */
	private function get_matchers() {
		$matchers = apply_filters(
			'msim_matchers',
			array(
				new ParentProductSkuMatcher(),
				new ProductIdMatcher(),
			)
		);

		return array_values(
			array_filter(
				(array) $matchers,
				static function ( $matcher ) {
					return $matcher instanceof MatcherInterface;
				}
			)
		);
	}
}
