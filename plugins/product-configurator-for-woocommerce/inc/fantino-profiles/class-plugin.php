<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fantino_PC_Plugin {

	/** @var Fantino_PC_Plugin|null */
	private static $instance = null;

	/** @var Fantino_PC_Repository */
	public $repository;

	/** @var Fantino_PC_Live_Structure */
	public $live_structure;

	/** @var Fantino_PC_Settings */
	public $settings;

	/** @var Fantino_PC_Admin|null */
	public $admin = null;

	/** @var Fantino_PC_Filter */
	public $filter;

	/** @var Fantino_PC_Frontend|null */
	public $frontend = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->live_structure = new Fantino_PC_Live_Structure();
		$this->repository     = new Fantino_PC_Repository();

		// Loader settings — hooks only fire in admin but ::get() is used on the frontend.
		$this->settings = new Fantino_PC_Settings();
		$this->settings->register();

		// Server-side filter runs in both contexts (admin AJAX uses it for previews,
		// frontend pages use it for normal customers).
		$this->filter = new Fantino_PC_Filter( $this->repository, $this->live_structure );
		$this->filter->register();

		if ( is_admin() ) {
			$this->admin = new Fantino_PC_Admin( $this->repository, $this->live_structure );
			$this->admin->register();
		} else {
			$this->frontend = new Fantino_PC_Frontend( $this->repository );
			$this->frontend->register();
		}
	}
}
