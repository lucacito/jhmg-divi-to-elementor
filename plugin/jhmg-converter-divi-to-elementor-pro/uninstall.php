<?php
/**
 * Fires on Pro plugin deletion (not deactivation).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'jhmgcofop_license_key' );
delete_option( 'jhmgcofop_license_state' );
delete_option( 'jhmgcofop_update_blocked' );
