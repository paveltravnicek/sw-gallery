<?php
/**
 * Odinstalace SW Fotogalerie – úklid dat.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Data galerie a licence.
delete_option( 'swg_gallery' );
delete_option( 'sw_gallery_license' );

// Naplánovaný cron na ověřování licence.
$swg_cron = wp_next_scheduled( 'sw_gallery_license_daily_check' );
if ( $swg_cron ) {
	wp_unschedule_event( $swg_cron, 'sw_gallery_license_daily_check' );
}
