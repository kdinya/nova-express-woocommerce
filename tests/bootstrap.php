<?php
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/mock-wp/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'NVX_PLUGIN_DIR' ) ) {
    define( 'NVX_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

spl_autoload_register(
    function ( $class ) {
        $prefix = 'NovaExpress\\';
        if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        $path     = NVX_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
);
