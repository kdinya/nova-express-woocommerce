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

if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
    require_once dirname( __DIR__ ) . '/vendor/autoload.php';
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

// Mock WordPress functions if absent in test runner
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}

if ( ! function_exists( 'wp_date' ) ) {
    function wp_date( $format, $timestamp = null ) {
        return gmdate( $format, $timestamp ?? time() );
    }
}

if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        private $formatted_total;
        public function __construct( $formatted_total = '180,00 грн.' ) {
            $this->formatted_total = $formatted_total;
        }
        public function get_formatted_order_total() {
            return $this->formatted_total;
        }
    }
}
