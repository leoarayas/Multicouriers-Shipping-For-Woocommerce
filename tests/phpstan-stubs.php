<?php
/**
 * PHPStan stubs for Multicouriers Shipping for WooCommerce.
 *
 * @phpstan-ignore-file
 */

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}
if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 2592000);
}
if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}

class WC_Shipping_Method
{
    /** @var string */
    public $id = '';

    /** @var int */
    public $instance_id = 0;

    /** @var string */
    public $method_title = '';

    /** @var string */
    public $method_description = '';

    /** @var string */
    public $title = '';

    /** @var string */
    public $enabled = 'yes';

    /** @var array<string, mixed> */
    public $instance_form_fields = array();

    /** @var array<string> */
    public $supports = array();

    public function __construct($instance_id = 0) {}

    /**
     * @param string $key
     * @param mixed $empty_value
     * @return mixed
     */
    public function get_option($key, $empty_value = null)
    {
        return $empty_value;
    }

    public function init_settings(): void {}

    public function init_form_fields(): void {}

    /**
     * @param array<string, mixed> $args
     */
    public function add_rate($args = array()): void {}
}

class WC_Order
{
    /** @return int */
    public function get_id() { return 0; }

    /** @return string */
    public function get_billing_country() { return ''; }

    /** @return string */
    public function get_billing_postcode() { return ''; }

    /** @return string */
    public function get_billing_city() { return ''; }

    /** @return string */
    public function get_billing_state() { return ''; }

    /** @param string $postcode */
    public function set_billing_postcode($postcode): void {}

    /** @return string */
    public function get_shipping_country() { return ''; }

    /** @return string */
    public function get_shipping_city() { return ''; }

    /** @return string */
    public function get_shipping_state() { return ''; }

    /** @return string */
    public function get_shipping_postcode() { return ''; }

    /** @param string $postcode */
    public function set_shipping_postcode($postcode): void {}

    /**
     * @param string $key
     * @param mixed $value
     */
    public function update_meta_data($key, $value): void {}

    /** @return int */
    public function save() { return 0; }
}

class WC_Cart
{
    /** @return array<mixed> */
    public function get_cart() { return array(); }
}
