<?php
/**
 * Lexware Rechnungs-E-Mail (HTML)
 */

if (!defined('ABSPATH')) {
    exit;
}

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p><?php printf(__('Hallo %s,', 'lexware-connector-for-woocommerce'), esc_html($order->get_billing_first_name())); ?></p>

<p><?php _e('vielen Dank für Ihre Bestellung. Im Anhang finden Sie Ihre Rechnung als PDF.', 'lexware-connector-for-woocommerce'); ?></p>

<h2><?php printf(__('Bestellung #%s', 'lexware-connector-for-woocommerce'), esc_html($order->get_order_number())); ?></h2>

<?php
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
