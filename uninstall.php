<?php
/**
 * Uninstall script
 * Führt Cleanup beim Deinstallieren aus
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Lösche alle Plugin-Optionen
$options_to_delete = array(
    'wlc_api_key',
    'wlc_order_statuses',
    'wlc_retry_attempts',
    'wlc_invoice_title',
    'wlc_invoice_introduction',
    'wlc_payment_terms',
    'wlc_payment_due_days',
    'wlc_closing_text',
    'wlc_finalize_immediately',
    'wlc_auto_sync_contacts',
    'wlc_show_in_customer_area',
    'wlc_shipping_as_line_item',
    'wlc_enable_logging',
    'wlc_email_on_error',
    'wlc_auto_send_email',
    'wlc_error_logs'
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

if (function_exists('WC')) {
    $payment_gateways = WC()->payment_gateways->payment_gateways();
    foreach ($payment_gateways as $gateway) {
        delete_option('wlc_payment_terms_' . $gateway->id);
        delete_option('wlc_payment_due_days_' . $gateway->id);
    }
}

// DROP TABLE mit esc_sql() gesichert (DirectDB ok für uninstall.php)
$wpdb->query(
    $wpdb->prepare(
        'DROP TABLE IF EXISTS %i',
        $wpdb->prefix . 'wlc_queue'
    )
);

$orders = get_posts(array(
    'post_type' => 'shop_order',
    'posts_per_page' => -1,
    'fields' => 'ids'
));

if (function_exists('wc_get_orders')) {
    $hpos_orders = wc_get_orders(array(
        'limit' => -1,
        'return' => 'ids'
    ));
    $orders = array_merge($orders, $hpos_orders);
}

$meta_keys_to_delete = array(
    '_wlc_lexware_invoice_id',
    '_wlc_lexware_invoice_number',
    '_wlc_lexware_credit_note_id',
    '_wlc_lexware_invoice_voided',
    '_wlc_lexware_contact_id'
);

foreach ($orders as $order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        foreach ($meta_keys_to_delete as $meta_key) {
            $order->delete_meta_data($meta_key);
        }
        $order->save();
    }
}

$upload_dir = wp_upload_dir();
$pdf_dir = $upload_dir['basedir'] . '/lexware-invoices';

if (file_exists($pdf_dir)) {
    // WP_Filesystem für Cleanup
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    
    $files = $wp_filesystem->dirlist($pdf_dir);
    if ($files) {
        foreach ($files as $file => $fileinfo) {
            if ($fileinfo['type'] === 'f') {
                $wp_filesystem->delete($pdf_dir . '/' . $file);
            }
        }
    }
    
    $wp_filesystem->rmdir($pdf_dir);
}

delete_transient('wlc_api_test_result');

// DirectDB ok für uninstall.php
$rate_limit_transients = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_wlc_rate_limit_%'"
);

foreach ($rate_limit_transients as $transient) {
    $key = str_replace('_transient_', '', $transient);
    delete_transient($key);
}
