<?php
/**
 * Plugin Name: Mercado Pago for FluentCart
 * Plugin URI: https://fluentcart.com
 * Description: Accept payments via Mercado Pago in FluentCart - supports one-time payments, subscriptions, and automatic refunds via webhooks.
 * Version: 1.0.1
 * Author: FluentCart
 * Author URI: https://fluentcart.com
 * Text Domain: mercado-pago-for-fluent-cart
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
defined('ABSPATH') || exit('Direct access not allowed.');

// Define plugin constants
define('MERCADOPAGO_FCT_VERSION', '1.0.1');
define('MERCADOPAGO_FCT_PLUGIN_FILE', __FILE__);
define('MERCADOPAGO_FCT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MERCADOPAGO_FCT_PLUGIN_URL', plugin_dir_url(__FILE__));


function mercadopago_fct_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Mercado Pago for FluentCart', 'mercado-pago-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart to be installed and activated.', 'mercado-pago-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    
    if (version_compare(FLUENTCART_VERSION, '1.2.5', '<')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Mercado Pago for FluentCart', 'mercado-pago-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart version 1.2.5 or higher', 'mercado-pago-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    
    return true;
}


add_action('plugins_loaded', function() {
    if (!mercadopago_fct_check_dependencies()) {
        return;
    }

    spl_autoload_register(function ($class) {
        $prefix = 'MercadoPagoFluentCart\\';
        $base_dir = MERCADOPAGO_FCT_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    add_action('fluent_cart/register_payment_methods', function($data) {
        \MercadoPagoFluentCart\MercadoPagoGateway::register();
    }, 10);

    /**
     * Plugin Updater
     */
    $apiUrl = 'https://fluentcart.com/wp-admin/admin-ajax.php?action=fluent_cart_mercado_pago_update&time=' . time();
    new \MercadoPagoFluentCart\PluginManager\Updater($apiUrl, MERCADOPAGO_FCT_PLUGIN_FILE, array(
        'version'   => MERCADOPAGO_FCT_VERSION,
        'license'   => '12345',
        'item_name' => 'Mercado Pago for FluentCart',
        'item_id'   => '103',
        'author'    => 'wpmanageninja'
    ),
        array(
            'license_status' => 'valid',
            'admin_page_url' => admin_url('admin.php?page=fluent-cart#/'),
            'purchase_url'   => 'https://fluentcart.com',
            'plugin_title'   => 'Mercado Pago for FluentCart'
        )
    );

    add_filter('plugin_row_meta', function ($links, $pluginFile) {
        if (plugin_basename(MERCADOPAGO_FCT_PLUGIN_FILE) !== $pluginFile) {
            return $links;
        }

        $checkUpdateUrl = esc_url(admin_url('plugins.php?mercado-pago-for-fluent-cart-check-update=' . time()));

        $row_meta = array(
            'check_update' => '<a style="color: #583fad;font-weight: 600;" href="' . $checkUpdateUrl . '" aria-label="' . esc_attr__('Check Update', 'mercado-pago-for-fluent-cart') . '">' . esc_html__('Check Update', 'mercado-pago-for-fluent-cart') . '</a>',
        );

        return array_merge($links, $row_meta);
    }, 10, 2);

}, 20);


register_activation_hook( __FILE__,  'mercadopago_fct_on_activation');

function mercadopago_fct_on_activation() {
    if (!mercadopago_fct_check_dependencies()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Mercado Pago for FluentCart requires FluentCart to be installed and activated.', 'mercado-pago-for-fluent-cart'),
            esc_html__('Plugin Activation Error', 'mercado-pago-for-fluent-cart'),
            ['back_link' => true]
        );
    }
    
    $default_options = [
        'MERCADOPAGO_FCT_VERSION' => MERCADOPAGO_FCT_VERSION,
        'mercadopago_fct_installed_time' => current_time('timestamp'),
    ];
    
    foreach ($default_options as $option => $value) {
        add_option($option, $value);
    }
    
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}