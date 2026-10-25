<?php
/**
 * Main plugin orchestrator.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

/**
 * Wires CPT, uploads, indexing, admin, front-end, OCR hook.
 */
final class Plugin {

	private static ?self $instance = null;

	public Format_Registry $formats;
	public Ingestor $ingestor;
	public Indexer $indexer;
	public Ocr_Hook $ocr;
	public Admin $admin;
	public Frontend $frontend;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->formats  = new Format_Registry();
		$this->ingestor = new Ingestor( $this->formats );
		$this->indexer  = new Indexer();
		$this->ocr      = new Ocr_Hook();
		$this->admin    = new Admin( $this->ingestor );
		$this->frontend = new Frontend();

		add_action( 'init', array( Post_Type::class, 'register' ) );
		add_action( 'init', array( Taxonomies::class, 'register' ) );
		add_action( 'init', array( $this, 'register_mime_types' ) );

		$this->admin->hooks();
		$this->frontend->hooks();
		$this->ocr->hooks();
		$this->indexer->hooks();
		$this->ingestor->hooks();
	}

	/**
	 * Activation: CPT flush + upload dir.
	 */
	public static function activate(): void {
		Post_Type::register();
		Taxonomies::register();
		flush_rewrite_rules();

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'booklove';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = trailingslashit( $upload['basedir'] ) . 'booklove/index';
		if ( ! is_dir( $index ) ) {
			wp_mkdir_p( $index );
		}

		update_option( 'booklove_version', BOOKLOVE_VERSION );
	}

	/**
	 * Deactivation flush.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Ensure WordPress accepts book MIME types on upload.
	 */
	public function register_mime_types(): void {
		add_filter( 'upload_mimes', array( $this->formats, 'filter_upload_mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this->formats, 'fix_filetype' ), 10, 5 );
	}
}
