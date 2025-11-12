<?php
/**
 * Lexware Rechnungs-E-Mail (Plain Text)
 */

if (!defined('ABSPATH')) {
    exit;
}

echo "= " . $email_heading . " =\n\n";

printf(__('Hallo %s,', 'lexware-connector-for-woocommerce'), esc_html($order->get_billing_first_name()));

echo "\n\n";

_e('vielen Dank für Ihre Bestellung. Im Anhang finden Sie Ihre Rechnung als PDF.', 'lexware-connector-for-woocommerce');

echo "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n\n";

echo wp_kses_post(wpautop(wptexturize($additional_content)));

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

echo apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'));
