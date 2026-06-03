<?php
/**
 * Plugin Name:       SW Fotogalerie
 * Plugin URI:        https://smart-websites.cz/
 * Description:       Designová fotogalerie s kategoriemi, subkategoriemi a vlastním lightboxem. Vkládá se shortcodem [sw_gallery].
 * Version:           1.0
 * Requires PHP:      7.4
 * Author:            Smart Websites
 * Author URI:        https://smart-websites.cz/
 * Update URI:        https://github.com/paveltravnicek/sw-gallery/
 * Text Domain:       sw-gallery
 * License:           GPL-2.0-or-later
 * SW Plugin:         yes
 * SW Service Type:   active
 * SW License Group:  both
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWG_VERSION', '1.0' );
define( 'SWG_FILE', __FILE__ );
define( 'SWG_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWG_URL', plugin_dir_url( __FILE__ ) );
define( 'SWG_OPTION', 'swg_gallery' );

require_once SWG_DIR . 'includes/class-swg-data.php';
require_once SWG_DIR . 'includes/class-swg-licence.php';
require_once SWG_DIR . 'includes/class-swg-admin.php';
require_once SWG_DIR . 'includes/class-swg-shortcode.php';

/**
 * Plugin Update Checker (YahnisElsts) – napojení na GitHub.
 * Knihovna se do pluginu vkládá zvlášť (složka /plugin-update-checker/),
 * proto je require guardované file_exists(), aby plugin nespadl bez ní.
 */
$swg_puc = SWG_DIR . 'plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $swg_puc ) ) {
	require_once $swg_puc;
	if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		$swg_uc = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/paveltravnicek/sw-gallery/',
			SWG_FILE,
			'sw-gallery'
		);
		if ( method_exists( $swg_uc, 'setBranch' ) ) {
			$swg_uc->setBranch( 'main' );
		}
		if ( method_exists( $swg_uc, 'getVcsApi' ) ) {
			$swg_vcs = $swg_uc->getVcsApi();
			if ( $swg_vcs && method_exists( $swg_vcs, 'enableReleaseAssets' ) ) {
				$swg_vcs->enableReleaseAssets();
			}
		}
	}
}

function swg_init() {
	SWG_Licence::instance();
	SWG_Shortcode::instance();
	if ( is_admin() ) {
		SWG_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'swg_init' );

register_activation_hook(
	__FILE__,
	function () {
		SWG_Data::maybe_seed();
		SWG_Licence::schedule_cron();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		SWG_Licence::clear_cron();
	}
);
