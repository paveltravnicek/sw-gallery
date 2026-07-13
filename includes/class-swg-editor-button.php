<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tlačítko "Fotogalerie" vedle "Přidat médium" v klasickém editoru příspěvku.
 * Otevře jednoduchý výběr kategorie a vloží odpovídající shortcode do těla příspěvku.
 *
 * Jde o doplňkovou pohodlnostní funkci nad rámec administrace samotného pluginu –
 * pokud web/editor klasický editor nepoužívá, tlačítko se jednoduše nezobrazí.
 */
class SWG_Editor_Button {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'media_buttons', array( $this, 'render_button' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/** Kategorie s vyplněným slugem, seřazené jako v administraci. */
	private function get_categories() {
		$data = SWG_Data::get();
		$out  = array();
		foreach ( $data['categories'] as $cat ) {
			if ( empty( $cat['slug'] ) || empty( $cat['title'] ) ) {
				continue;
			}
			$out[] = array(
				'title' => $cat['title'],
				'slug'  => $cat['slug'],
			);
		}
		return $out;
	}

	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$categories = $this->get_categories();
		if ( empty( $categories ) ) {
			return;
		}

		wp_enqueue_style( 'swg-editor-btn', SWG_URL . 'assets/editor-button.css', array(), SWG_VERSION );

		wp_enqueue_script(
			'swg-editor-btn',
			SWG_URL . 'assets/editor-button.js',
			array( 'jquery' ),
			SWG_VERSION,
			true
		);

		wp_localize_script(
			'swg-editor-btn',
			'SWG_EDITOR',
			array(
				'categories' => $categories,
				'i18n'       => array(
					'modalTitle'   => 'Vložit fotogalerii',
					'allGalleries' => 'Celá fotogalerie (všechny kategorie)',
					'cancel'       => 'Zrušit',
				),
			)
		);
	}

	public function render_button() {
		if ( empty( $this->get_categories() ) ) {
			return;
		}
		echo '<button type="button" id="swg-insert-gallery-btn" class="button swg-media-btn"><span class="dashicons dashicons-format-gallery"></span> Fotogalerie</button>';
	}
}
