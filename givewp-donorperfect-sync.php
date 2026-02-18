<?php
/**
 * Plugin Name: GiveWP to DonorPerfect Sync
 * Description: Automatically syncs GiveWP donations to DonorPerfect in real-time with donor matching, backfill support, and comprehensive logging.
 * Version: 1.0.0
 * Author: wavedepth
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

if (!defined('ABSPATH')) exit;

define('GWDP_VERSION', '1.0.0');
define('GWDP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GWDP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GWDP_PLUGIN_DIR . 'includes/class-dp-api.php';
require_once GWDP_PLUGIN_DIR . 'includes/class-donation-sync.php';
require_once GWDP_PLUGIN_DIR . 'includes/class-admin-page.php';

register_activation_hook(__FILE__, ['GWDP_Donation_Sync', 'activate']);

add_action('plugins_loaded', function () {
    GWDP_Donation_Sync::instance();
    if (is_admin()) {
        GWDP_Admin_Page::instance();
    }
});
