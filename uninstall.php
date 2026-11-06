<?php
/**
 * Při smazání pluginu odstraní jeho nastavení. Komentáře ani stav „zavřeno“ u příspěvků nemění.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'hbk_settings' );
		restore_current_blog();
	}
} else {
	delete_option( 'hbk_settings' );
}
