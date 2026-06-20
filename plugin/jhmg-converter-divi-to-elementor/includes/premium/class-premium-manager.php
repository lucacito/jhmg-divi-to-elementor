<?php

namespace DiviElementorConverter\Premium;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PremiumManager {
    private const OPTION_KEY = 'dec_premium_active';

    public static function is_active(): bool {
        return (bool) get_option( self::OPTION_KEY, false );
    }

    public static function activate(): void {
        update_option( self::OPTION_KEY, true );
    }

    public static function deactivate(): void {
        delete_option( self::OPTION_KEY );
    }
}
