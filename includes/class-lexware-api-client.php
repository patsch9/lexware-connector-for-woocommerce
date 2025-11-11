<?php
/**
 * Lexware API Client
 * Handhabt alle Kommunikation mit der Lexware Office Public API
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_Lexware_API_Client {

    // ... alle bisherigen Methoden ...

    /**
     * Ersetzt Shortcodes im Rechnungstext durch die passenden Werte aus der Bestellung
     *
     * @param string $text
     * @param WC_Order $order
     * @return string
     */
    public function replace_shortcodes($text, $order) {
        if (!$order) return $text;
        $replace = array(
            '[order_number]'     => $order->get_order_number(),
            '[order_date]'       => date_i18n(get_option('date_format'), strtotime($order->get_date_created())),
            '[customer_name]'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '[customer_company]' => $order->get_billing_company(),
            '[total]'            => wc_price($order->get_total()),
            '[payment_method]'   => $order->get_payment_method_title(),
        );
        return strtr($text, $replace);
    }

    // ...der Rest der Klasse bleibt unverändert...

}
