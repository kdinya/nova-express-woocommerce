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

// In-memory options mock store
if ( ! isset( $GLOBALS['_mock_wp_options'] ) ) {
    $GLOBALS['_mock_wp_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        if ( isset( $GLOBALS['_mock_wp_options'][ $option ] ) ) {
            return $GLOBALS['_mock_wp_options'][ $option ];
        }
        return $default;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
        if ( isset( $GLOBALS['_mock_wp_options'][ $option ] ) ) {
            return false;
        }
        $GLOBALS['_mock_wp_options'][ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        $GLOBALS['_mock_wp_options'][ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $option ) {
        if ( isset( $GLOBALS['_mock_wp_options'][ $option ] ) ) {
            unset( $GLOBALS['_mock_wp_options'][ $option ] );
            return true;
        }
        return false;
    }
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '' ) {
        if ( 'name' === $show ) {
            return 'Nova Store';
        }
        return '';
    }
}

if ( ! function_exists( 'date_i18n' ) ) {
    function date_i18n( $format, $timestamp = null ) {
        return gmdate( $format, $timestamp ?? time() );
    }
}

if ( ! function_exists( 'wc_get_order_status_name' ) ) {
    function wc_get_order_status_name( $status ) {
        $statuses = array(
            'processing' => 'В обробці',
            'completed'  => 'Виконано',
            'pending'    => 'В очікуванні',
        );
        return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
    }
}

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

if ( ! class_exists( 'WC_DateTime_Mock' ) ) {
    class WC_DateTime_Mock {
        private $timestamp;
        public function __construct( $timestamp = null ) {
            $this->timestamp = $timestamp ?: 1769000000;
        }
        public function date_i18n( $format ) {
            return gmdate( $format, $this->timestamp );
        }
    }
}

if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        private $id;
        private $formatted_total;
        private $meta = array();
        private $status = 'processing';
        private $total = 180.00;
        private $payment_method = 'bacs';
        private $payment_method_title = 'Накладений платіж';
        private $currency = 'UAH';
        private $billing_first_name = 'Тарас';
        private $billing_last_name = 'Шевченко';
        private $billing_phone = '0671234567';
        private $billing_email = 'taras@example.com';
        private $shipping_city = 'Київ';
        private $item_count = 2;

        public function __construct( $formatted_total = '180,00 грн.', $id = 1001 ) {
            $this->formatted_total = $formatted_total;
            $this->id = $id;
        }

        public function get_id() { return $this->id; }
        public function get_order_number() { return (string) $this->id; }
        public function get_status() { return $this->status; }
        public function set_status( $status ) { $this->status = $status; }
        public function get_total() { return $this->total; }
        public function get_payment_method_title() { return $this->payment_method_title; }
        public function get_currency() { return $this->currency; }
        public function get_date_created() { return new WC_DateTime_Mock(); }
        public function get_formatted_order_total() { return $this->formatted_total; }
        public function get_billing_first_name() { return $this->billing_first_name; }
        public function get_billing_last_name() { return $this->billing_last_name; }
        public function get_billing_email() { return $this->billing_email; }
        public function get_billing_phone() { return $this->billing_phone; }
        public function get_shipping_first_name() { return $this->billing_first_name; }
        public function get_shipping_last_name() { return $this->billing_last_name; }
        public function get_shipping_city() { return $this->shipping_city; }
        public function get_item_count() { return $this->item_count; }

        public function get_meta( $key, $single = true, $context = 'view' ) {
            return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
        }

        public function set_meta_data( $key, $value ) {
            $this->meta[ $key ] = $value;
        }

        public function save() { return true; }
    }
}
