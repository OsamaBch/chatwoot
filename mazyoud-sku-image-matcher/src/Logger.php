<?php
/**
 * WC_Logger wrapper.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around WC_Logger writing to the "msim" source so entries are
 * viewable under WooCommerce → Status → Logs.
 */
class Logger {

	/**
	 * Log source identifier.
	 *
	 * @var string
	 */
	private const SOURCE = 'msim';

	/**
	 * Lazily instantiated WooCommerce logger.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private $logger = null;

	/**
	 * Log an informational message.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra context (merged into the line).
	 * @return void
	 */
	public function info( $message, array $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Write a log entry. Never throws: logging must not break the caller.
	 *
	 * @param string $level   WC log level (debug|info|notice|warning|error|critical).
	 * @param string $message Message.
	 * @param array  $context Extra context appended as JSON.
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ) {
		try {
			if ( null === $this->logger ) {
				if ( ! function_exists( 'wc_get_logger' ) ) {
					return;
				}
				$this->logger = wc_get_logger();
			}

			if ( ! empty( $context ) ) {
				$message .= ' | ' . wp_json_encode( $context );
			}

			$this->logger->log( $level, $message, array( 'source' => self::SOURCE ) );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Swallow: logging failures must never break processing.
		}
	}
}
