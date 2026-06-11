<?php
/**
 * Applies image assignments to products (and restores them on rollback).
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

use Mazyoud\SkuImageMatcher\Matching\MatchResult;

defined( 'ABSPATH' ) || exit;

/**
 * All product writes happen here:
 *
 *  - run path: snapshot first, then absolute writes computed from the
 *    snapshot state + plan + mode, so a retried job recomputes the exact
 *    same target (idempotent and safely resumable);
 *  - rollback path: restores featured image, gallery and attachment parents
 *    from a snapshot, with manual-edit conflict detection;
 *  - auto-attach path: single-file assignment without snapshots.
 *
 * After every product update caches are invalidated
 * (wc_delete_product_transients + clean_post_cache) and the public
 * `msim_product_images_updated` action fires so page-cache/CDN purges can
 * hook in.
 */
class Assigner {

	/**
	 * Marker meta set on products whose images this plugin manages. Used by
	 * auto-attach "skip" mode to distinguish plugin-built galleries from
	 * manually curated ones.
	 */
	public const MANAGED_META = '_msim_managed';

	/**
	 * Constructor.
	 *
	 * @param SnapshotRepository $snapshots Snapshot repository.
	 * @param Logger             $logger    Logger.
	 */
	public function __construct(
		private SnapshotRepository $snapshots,
		private Logger $logger
	) {
	}

	/**
	 * Apply one product's plan within a run. Snapshot is written BEFORE any
	 * modification; an existing terminal snapshot short-circuits (idempotent
	 * retries). Never throws.
	 *
	 * @param int    $run_id       Run ID.
	 * @param array  $product_plan Plan entry: product_id, featured, gallery, files.
	 * @param string $mode         skip|replace|merge.
	 * @return string Resulting snapshot status.
	 */
	public function apply_product_plan( $run_id, array $product_plan, $mode ) {
		$product_id = (int) $product_plan['product_id'];

		try {
			$existing = $this->snapshots->get( $run_id, $product_id );
			if ( $existing && in_array( $existing->status, SnapshotRepository::TERMINAL, true ) ) {
				return $existing->status;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			if ( ! $product || 'trash' === $product->get_status() ) {
				return $this->fail( $run_id, $product_id, 'Product missing or trashed at run time.' );
			}

			// Re-validate plan attachments (they may have been deleted
			// between scan and run).
			$featured = (int) $product_plan['featured'];
			$gallery  = array_map( 'intval', (array) $product_plan['gallery'] );

			$valid_featured = $this->is_valid_image_attachment( $featured ) ? $featured : 0;
			$valid_gallery  = array_values( array_filter( $gallery, array( $this, 'is_valid_image_attachment' ) ) );

			if ( 0 === $valid_featured && ! empty( $valid_gallery ) ) {
				$valid_featured = array_shift( $valid_gallery );
				$this->logger->warning(
					'Planned featured attachment missing; promoted first gallery image.',
					array(
						'run_id'     => $run_id,
						'product_id' => $product_id,
					)
				);
			}

			if ( 0 === $valid_featured ) {
				return $this->fail( $run_id, $product_id, 'No planned attachments exist anymore.' );
			}

			$plan_attachments = array_values( array_unique( array_merge( array( $valid_featured ), $valid_gallery ) ) );

			// Snapshot of prior state (insert once; reused on retry).
			$snapshot = $existing;
			if ( ! $snapshot ) {
				$thumb_exists   = metadata_exists( 'post', $product_id, '_thumbnail_id' );
				$gallery_exists = metadata_exists( 'post', $product_id, '_product_image_gallery' );

				$snapshot = $this->snapshots->create(
					$run_id,
					$product_id,
					$thumb_exists ? (int) get_post_meta( $product_id, '_thumbnail_id', true ) : null,
					$gallery_exists ? (string) get_post_meta( $product_id, '_product_image_gallery', true ) : null,
					$this->capture_parents( $plan_attachments ),
					metadata_exists( 'post', $product_id, self::MANAGED_META )
				);
			}

			if ( ! $snapshot ) {
				return $this->fail( $run_id, $product_id, 'Could not write snapshot row; aborting before modification.' );
			}

			$prev_thumb   = null === $snapshot->prev_thumbnail_id ? 0 : (int) $snapshot->prev_thumbnail_id;
			$prev_gallery = $this->ids_from_gallery( $snapshot->prev_gallery );

			// "Skip products that already have images" — re-checked at run
			// time against the snapshot state (it may have changed since scan).
			if ( Settings::MODE_SKIP === $mode && ( $prev_thumb > 0 || ! empty( $prev_gallery ) ) ) {
				$this->snapshots->set_status( $snapshot->id, SnapshotRepository::STATUS_SKIPPED, 'Product already had images.' );
				$this->logger->info( 'Skipped (already has images).', array( 'run_id' => $run_id, 'product_id' => $product_id ) );

				return SnapshotRepository::STATUS_SKIPPED;
			}

			list( $target_thumb, $target_gallery ) = $this->compute_target( $mode, $prev_thumb, $prev_gallery, $valid_featured, $valid_gallery );

			$this->write_product_images( $product_id, $target_thumb, $target_gallery, $plan_attachments, (int) $run_id );

			$new_gallery_string = implode( ',', $target_gallery );
			$this->snapshots->set_status( $snapshot->id, SnapshotRepository::STATUS_DONE, null, $target_thumb, $new_gallery_string );

			$this->logger->info(
				'Images assigned.',
				array(
					'run_id'     => $run_id,
					'product_id' => $product_id,
					'featured'   => $target_thumb,
					'gallery'    => $new_gallery_string,
					'mode'       => $mode,
				)
			);

			return SnapshotRepository::STATUS_DONE;
		} catch ( \Throwable $e ) {
			return $this->fail( $run_id, $product_id, $e->getMessage() );
		}
	}

	/**
	 * Restore one product from its snapshot row.
	 *
	 * Before restoring, the product's current state is compared with the
	 * state the run produced; if they differ (manually edited since), the
	 * row is flagged STATUS_ROLLBACK_CONFLICT and skipped unless $force.
	 *
	 * @param object $snapshot Snapshot row.
	 * @param bool   $force    Overwrite even when manually edited since.
	 * @return string Resulting status.
	 */
	public function rollback_snapshot( $snapshot, $force = false ) {
		$product_id = (int) $snapshot->product_id;

		try {
			if ( SnapshotRepository::STATUS_ROLLED_BACK === $snapshot->status ) {
				return $snapshot->status;
			}

			if ( ! get_post( $product_id ) ) {
				$this->snapshots->set_status( $snapshot->id, SnapshotRepository::STATUS_ROLLBACK_CONFLICT, 'Product no longer exists.' );

				return SnapshotRepository::STATUS_ROLLBACK_CONFLICT;
			}

			// Conflict detection only makes sense for rows the run completed
			// (failed/pending rows are restored unconditionally — the
			// snapshot is their only known-good state).
			if ( ! $force && SnapshotRepository::STATUS_DONE === $snapshot->status ) {
				$difference = $this->diff_from_run_state( $snapshot );
				if ( '' !== $difference ) {
					$this->snapshots->set_status( $snapshot->id, SnapshotRepository::STATUS_ROLLBACK_CONFLICT, $difference );
					$this->logger->warning(
						'Rollback conflict (manually edited since run); requires confirmation.',
						array(
							'run_id'     => (int) $snapshot->run_id,
							'product_id' => $product_id,
							'difference' => $difference,
						)
					);

					return SnapshotRepository::STATUS_ROLLBACK_CONFLICT;
				}
			}

			// Restore featured image.
			$prev_thumb = null === $snapshot->prev_thumbnail_id ? 0 : (int) $snapshot->prev_thumbnail_id;
			if ( $prev_thumb > 0 ) {
				set_post_thumbnail( $product_id, $prev_thumb );
			} else {
				delete_post_thumbnail( $product_id );
			}

			// Restore gallery meta exactly as it was (absent vs present-but-empty).
			if ( null === $snapshot->prev_gallery ) {
				delete_post_meta( $product_id, '_product_image_gallery' );
			} else {
				update_post_meta( $product_id, '_product_image_gallery', (string) $snapshot->prev_gallery );
			}

			// Restore each touched attachment's previous post_parent.
			$parents = json_decode( (string) $snapshot->prev_parents, true );
			foreach ( (array) $parents as $att_id => $prev_parent ) {
				$att_id = (int) $att_id;
				$post   = get_post( $att_id );
				if ( $post && 'attachment' === $post->post_type && (int) $post->post_parent !== (int) $prev_parent ) {
					wp_update_post(
						array(
							'ID'          => $att_id,
							'post_parent' => (int) $prev_parent,
						)
					);
				}
			}

			if ( ! $snapshot->prev_managed ) {
				delete_post_meta( $product_id, self::MANAGED_META );
			}

			$this->invalidate( $product_id );

			$this->snapshots->set_status( $snapshot->id, SnapshotRepository::STATUS_ROLLED_BACK, null );
			$this->logger->info(
				'Rollback restored product.',
				array(
					'run_id'     => (int) $snapshot->run_id,
					'product_id' => $product_id,
					'forced'     => (bool) $force,
				)
			);

			return SnapshotRepository::STATUS_ROLLED_BACK;
		} catch ( \Throwable $e ) {
			$this->snapshots->set_status( $snapshot->id, SnapshotRepository::STATUS_ROLLBACK_CONFLICT, 'Rollback error: ' . $e->getMessage() );
			$this->logger->error(
				'Rollback failed.',
				array(
					'run_id'     => (int) $snapshot->run_id,
					'product_id' => $product_id,
					'error'      => $e->getMessage(),
				)
			);

			return SnapshotRepository::STATUS_ROLLBACK_CONFLICT;
		}
	}

	/**
	 * Auto-attach path: assign a single just-uploaded attachment. No
	 * snapshot (per spec) but every action is logged. Never throws.
	 *
	 * Mode semantics for a single file (documented in README):
	 *  - skip: the featured slot is only filled when empty; gallery files are
	 *    only appended to products without images or whose images this
	 *    plugin manages (_msim_managed marker) — curated products stay
	 *    untouched;
	 *  - replace: a bare file overwrites the featured image; gallery files
	 *    append (a single upload never wipes an existing gallery);
	 *  - merge: keeps an existing featured image (bare file joins the
	 *    gallery instead), appends gallery files, never duplicates an
	 *    attachment ID.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param MatchResult $result        Match result for the file.
	 * @param string      $mode          skip|replace|merge.
	 * @return string Outcome: assigned_featured|assigned_gallery|skipped.
	 */
	public function attach_single( $attachment_id, MatchResult $result, $mode ) {
		$attachment_id = (int) $attachment_id;
		$product_id    = (int) $result->product_id;

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product || 'trash' === $product->get_status() ) {
			$this->logger->warning( 'Auto-attach: product missing or trashed.', array( 'product_id' => $product_id, 'attachment_id' => $attachment_id ) );

			return 'skipped';
		}

		$thumb   = (int) get_post_meta( $product_id, '_thumbnail_id', true );
		$gallery = $this->ids_from_gallery( (string) get_post_meta( $product_id, '_product_image_gallery', true ) );
		$managed = metadata_exists( 'post', $product_id, self::MANAGED_META );

		$already_present = $attachment_id === $thumb || in_array( $attachment_id, $gallery, true );

		if ( MatchResult::ROLE_MAIN === $result->role ) {
			if ( $thumb === $attachment_id ) {
				return 'skipped';
			}

			if ( $thumb > 0 && Settings::MODE_SKIP === $mode ) {
				$this->logger->info( 'Auto-attach skipped: featured image already set (skip mode).', array( 'product_id' => $product_id, 'attachment_id' => $attachment_id ) );

				return 'skipped';
			}

			if ( $thumb > 0 && Settings::MODE_MERGE === $mode ) {
				// Keep the existing featured image; append to gallery instead.
				if ( $already_present ) {
					return 'skipped';
				}
				$gallery[] = $attachment_id;
				$this->write_product_images( $product_id, $thumb, $gallery, array( $attachment_id ), 0 );
				$this->logger->info( 'Auto-attach: appended bare file to gallery (merge mode, featured kept).', array( 'product_id' => $product_id, 'attachment_id' => $attachment_id ) );

				return 'assigned_gallery';
			}

			// Featured slot empty, or replace mode: set the featured image.
			$gallery = array_values( array_diff( $gallery, array( $attachment_id ) ) );
			$this->write_product_images( $product_id, $attachment_id, $gallery, array( $attachment_id ), 0 );
			$this->logger->info( 'Auto-attach: set featured image.', array( 'product_id' => $product_id, 'attachment_id' => $attachment_id, 'identifier' => $result->identifier ) );

			return 'assigned_featured';
		}

		// Gallery role.
		if ( $already_present ) {
			return 'skipped';
		}

		$has_images = $thumb > 0 || ! empty( $gallery );
		if ( Settings::MODE_SKIP === $mode && $has_images && ! $managed ) {
			$this->logger->info( 'Auto-attach skipped: product has curated images (skip mode).', array( 'product_id' => $product_id, 'attachment_id' => $attachment_id ) );

			return 'skipped';
		}

		$gallery[] = $attachment_id;
		$this->write_product_images( $product_id, $thumb, $gallery, array( $attachment_id ), 0 );
		$this->logger->info(
			'Auto-attach: appended to gallery.',
			array(
				'product_id'    => $product_id,
				'attachment_id' => $attachment_id,
				'position'      => $result->position,
				'identifier'    => $result->identifier,
			)
		);

		return 'assigned_gallery';
	}

	/**
	 * Whether an attachment ID exists and is an image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_valid_image_attachment( $attachment_id ) {
		$post = get_post( (int) $attachment_id );

		return $post
			&& 'attachment' === $post->post_type
			&& 0 === strpos( (string) $post->post_mime_type, 'image/' );
	}

	/**
	 * Compute the absolute target (featured + gallery) from the snapshot
	 * state, the plan and the existing-images mode. Pure function: retries
	 * recompute the identical target. Gallery never contains duplicates or
	 * the featured ID.
	 *
	 * @param string $mode          skip|replace|merge.
	 * @param int    $prev_thumb    Previous featured ID (0 = none).
	 * @param int[]  $prev_gallery  Previous gallery IDs.
	 * @param int    $plan_featured Planned featured attachment.
	 * @param int[]  $plan_gallery  Planned gallery attachments (ordered).
	 * @return array{0:int, 1:int[]} Target featured + gallery.
	 */
	public function compute_target( $mode, $prev_thumb, array $prev_gallery, $plan_featured, array $plan_gallery ) {
		if ( Settings::MODE_MERGE === $mode && $prev_thumb > 0 ) {
			// Keep existing featured; planned files (including the planned
			// featured) are appended to the existing gallery.
			$thumb   = $prev_thumb;
			$gallery = array_merge( $prev_gallery, array( $plan_featured ), $plan_gallery );
		} elseif ( Settings::MODE_MERGE === $mode ) {
			$thumb   = $plan_featured;
			$gallery = array_merge( $prev_gallery, $plan_gallery );
		} else {
			// Replace mode, or skip mode on a product without images.
			$thumb   = $plan_featured;
			$gallery = $plan_gallery;
		}

		$clean = array();
		foreach ( $gallery as $id ) {
			$id = (int) $id;
			if ( $id > 0 && $id !== $thumb && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return array( (int) $thumb, $clean );
	}

	/**
	 * Perform the actual writes for a product: featured meta, gallery meta,
	 * post_parent of used attachments, managed marker — then invalidate
	 * caches and fire `msim_product_images_updated`.
	 *
	 * @param int   $product_id  Product ID.
	 * @param int   $thumb       Featured attachment ID (0 clears).
	 * @param int[] $gallery     Ordered gallery attachment IDs.
	 * @param int[] $attachments Attachments whose post_parent must point at the product.
	 * @param int   $run_id      Run ID for the managed marker (0 = auto-attach).
	 * @return void
	 */
	private function write_product_images( $product_id, $thumb, array $gallery, array $attachments, $run_id ) {
		if ( $thumb > 0 ) {
			set_post_thumbnail( $product_id, $thumb );
		} else {
			delete_post_thumbnail( $product_id );
		}

		update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_map( 'intval', $gallery ) ) );

		foreach ( array_unique( array_map( 'intval', $attachments ) ) as $att_id ) {
			$post = get_post( $att_id );
			if ( $post && 'attachment' === $post->post_type && (int) $post->post_parent !== (int) $product_id ) {
				wp_update_post(
					array(
						'ID'          => $att_id,
						'post_parent' => (int) $product_id,
					)
				);
			}
		}

		update_post_meta( $product_id, self::MANAGED_META, $run_id > 0 ? $run_id : time() );

		$this->invalidate( $product_id );

		/**
		 * Fires after this plugin updates a product's images.
		 *
		 * Hook page-cache / CDN purges here (LiteSpeed, Cloudflare, Varnish…).
		 *
		 * @param int $product_id Updated product ID.
		 */
		do_action( 'msim_product_images_updated', (int) $product_id );
	}

	/**
	 * Compare a product's current state with the state the run produced.
	 *
	 * @param object $snapshot Snapshot row (status done).
	 * @return string Empty when identical; otherwise a human-readable difference.
	 */
	private function diff_from_run_state( $snapshot ) {
		$product_id = (int) $snapshot->product_id;

		$current_thumb   = (int) get_post_meta( $product_id, '_thumbnail_id', true );
		$current_gallery = $this->ids_from_gallery( (string) get_post_meta( $product_id, '_product_image_gallery', true ) );

		$run_thumb   = null === $snapshot->new_thumbnail_id ? 0 : (int) $snapshot->new_thumbnail_id;
		$run_gallery = $this->ids_from_gallery( $snapshot->new_gallery );

		if ( $current_thumb !== $run_thumb ) {
			return sprintf( 'Featured image changed since run (now #%1$d, run set #%2$d).', $current_thumb, $run_thumb );
		}

		if ( $current_gallery !== $run_gallery ) {
			return 'Gallery changed since run.';
		}

		$parents = json_decode( (string) $snapshot->prev_parents, true );
		foreach ( (array) $parents as $att_id => $prev_parent ) {
			$post = get_post( (int) $att_id );
			if ( $post && (int) $post->post_parent !== $product_id ) {
				return sprintf( 'Attachment #%d was re-parented since run.', (int) $att_id );
			}
		}

		return '';
	}

	/**
	 * Capture the current post_parent of each attachment (pre-modification).
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return array<int,int> attachment_id => current parent.
	 */
	private function capture_parents( array $attachment_ids ) {
		$parents = array();
		foreach ( $attachment_ids as $att_id ) {
			$post = get_post( (int) $att_id );
			if ( $post ) {
				$parents[ (int) $att_id ] = (int) $post->post_parent;
			}
		}

		return $parents;
	}

	/**
	 * Parse a comma-separated gallery meta string into clean int IDs.
	 *
	 * @param string|null $gallery Gallery meta value.
	 * @return int[]
	 */
	public function ids_from_gallery( $gallery ) {
		if ( null === $gallery || '' === trim( (string) $gallery ) ) {
			return array();
		}

		$ids = array();
		foreach ( explode( ',', (string) $gallery ) as $part ) {
			$id = (int) trim( $part );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Invalidate product caches so Redis object cache stays consistent.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function invalidate( $product_id ) {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		clean_post_cache( $product_id );
	}

	/**
	 * Record a per-product failure (snapshot row keeps the reason).
	 *
	 * @param int    $run_id     Run ID.
	 * @param int    $product_id Product ID.
	 * @param string $reason     Failure reason.
	 * @return string STATUS_FAILED.
	 */
	private function fail( $run_id, $product_id, $reason ) {
		$snapshot = $this->snapshots->get( $run_id, $product_id );

		if ( ! $snapshot ) {
			$snapshot = $this->snapshots->create( $run_id, $product_id, null, null, array(), false );
		}

		if ( $snapshot ) {
			$this->snapshots->set_status( $snapshot->id, SnapshotRepository::STATUS_FAILED, $reason );
		}

		$this->logger->error(
			'Product assignment failed.',
			array(
				'run_id'     => (int) $run_id,
				'product_id' => (int) $product_id,
				'reason'     => $reason,
			)
		);

		return SnapshotRepository::STATUS_FAILED;
	}
}
