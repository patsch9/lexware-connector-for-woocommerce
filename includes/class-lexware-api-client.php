<?php
/**
 * Lexware API Client
 * Handhabt alle Kommunikation mit der Lexware Office Public API
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_Lexware_API_Client {

    const API_BASE_URL = 'https://api.lexoffice.io/v1/';
    private $api_key;

    public function __construct() {
        $this->api_key = get_option('wlc_api_key', '');
    }

    private function format_lexware_date($timestamp) {
        $date = new DateTime();
        $date->setTimestamp($timestamp);
        $date->setTimezone(new DateTimeZone('Europe/Berlin'));
        $date->setTime(0, 0, 0);
        return $date->format('Y-m-d\TH:i:s.000P');
    }

    public function sync_contact($order) {
        $lexware_contact_id = $order->get_meta('_wlc_lexware_contact_id');
        if ($lexware_contact_id) {
            return $this->update_contact($lexware_contact_id, $order);
        } else {
            return $this->create_contact($order);
        }
    }

    private function create_contact($order) {
        $billing_company = $order->get_billing_company();
        $is_company = !empty($billing_company);
        $contact_data = array(
            'version' => 0,
            'roles' => array('customer' => array())
        );
        if ($is_company) {
            $contact_data['company'] = array(
                'name' => $billing_company,
                'taxNumber' => $order->get_meta('_billing_tax_number') ?: '',
                'vatRegistrationId' => $order->get_meta('_billing_vat_id') ?: '',
                'allowTaxFreeInvoices' => !empty($order->get_meta('_billing_vat_id')),
                'contactPersons' => array(array(
                    'firstName' => $order->get_billing_first_name(),
                    'lastName' => $order->get_billing_last_name(),
                    'emailAddress' => $order->get_billing_email(),
                    'phoneNumber' => $order->get_billing_phone()
                ))
            );
        } else {
            $contact_data['person'] = array(
                'firstName' => $order->get_billing_first_name(),
                'lastName' => $order->get_billing_last_name()
            );
        }
        $contact_data['addresses'] = array('billing' => array(array(
            'street' => $order->get_billing_address_1(),
            'supplement' => $order->get_billing_address_2(),
            'zip' => $order->get_billing_postcode(),
            'city' => $order->get_billing_city(),
            'countryCode' => $order->get_billing_country()
        )));
        $contact_data['emailAddresses'] = array('business' => array($order->get_billing_email()));
        if ($order->get_billing_phone()) {
            $contact_data['phoneNumbers'] = array('business' => array($order->get_billing_phone()));
        }
        $response = $this->request('POST', 'contacts', $contact_data);
        if (!is_wp_error($response)) {
            $order->update_meta_data('_wlc_lexware_contact_id', $response['id']);
            $order->save();
        }
        return $response;
    }

    private function update_contact($contact_id, $order) {
        $current_contact = $this->request('GET', 'contacts/' . $contact_id);
        if (is_wp_error($current_contact)) {
            return $this->create_contact($order);
        }
        $billing_company = $order->get_billing_company();
        $is_company = !empty($billing_company);
        if ($is_company && isset($current_contact['company'])) {
            $current_contact['company']['name'] = $billing_company;
        } elseif (!$is_company && isset($current_contact['person'])) {
            $current_contact['person']['firstName'] = $order->get_billing_first_name();
            $current_contact['person']['lastName'] = $order->get_billing_last_name();
        }
        $current_contact['addresses']['billing'] = array(array(
            'street' => $order->get_billing_address_1(),
            'supplement' => $order->get_billing_address_2(),
            'zip' => $order->get_billing_postcode(),
            'city' => $order->get_billing_city(),
            'countryCode' => $order->get_billing_country()
        ));
        return $this->request('PUT', 'contacts/' . $contact_id, $current_contact);
    }

    public function create_invoice($order, $contact_id) {
        $finalize = get_option('wlc_finalize_immediately', 'yes') === 'yes';
        $order_date = $order->get_date_created();
        $voucher_date = $this->format_lexware_date($order_date->getTimestamp());
        $invoice_data = array(
            'voucherDate' => $voucher_date,
            'address' => $this->format_address($order),
            'lineItems' => $this->format_line_items($order),
            'totalPrice' => array('currency' => $order->get_currency()),
            'taxConditions' => array('taxType' => 'net'),
            'shippingConditions' => array('shippingDate' => $voucher_date, 'shippingType' => 'delivery'),
            'title' => get_option('wlc_invoice_title', 'Rechnung'),
            'introduction' => get_option('wlc_invoice_introduction', 'Vielen Dank für Ihre Bestellung.'),
            'remark' => get_option('wlc_closing_text', 'Vielen Dank für Ihr Vertrauen.')
        );
        if ($contact_id) {
            $invoice_data['address']['contactId'] = $contact_id;
        }
        $payment_due_days = $this->get_payment_due_days_for_order($order);
        $payment_terms = $this->get_payment_terms_for_order($order);
        $invoice_data['paymentConditions'] = array(
            'paymentTermLabel' => $this->replace_shortcodes($payment_terms, $order),
            'paymentTermDuration' => $payment_due_days
        );
        $endpoint = 'invoices';
        if ($finalize) {
            $endpoint .= '?finalize=true';
        }
        $response = $this->request('POST', $endpoint, $invoice_data);
        if (!is_wp_error($response)) {
            $order->update_meta_data('_wlc_lexware_invoice_id', $response['id']);
            $order->update_meta_data('_wlc_lexware_invoice_number', $response['voucherNumber'] ?? '');
            $order->save();
            $order->add_order_note(
                sprintf(
                    __('Lexware Rechnung erstellt: %s (ID: %s)', 'woo-lexware-connector'),
                    $response['voucherNumber'] ?? '',
                    $response['id']
                )
            );
        }
        return $response;
    }

    public function create_credit_note($order, $original_invoice_id) {
        $voucher_date = $this->format_lexware_date(time());
        $credit_note_data = array(
            'voucherDate' => $voucher_date,
            'address' => $this->format_address($order),
            'lineItems' => $this->format_line_items($order, true),
            'totalPrice' => array('currency' => $order->get_currency()),
            'taxConditions' => array('taxType' => 'net'),
            'title' => 'Gutschrift / Stornierung',
            'introduction' => sprintf('Gutschrift zur Rechnung (Lexware ID: %s)', $original_invoice_id)
        );
        $response = $this->request('POST', 'credit-notes?finalize=true', $credit_note_data);
        if (!is_wp_error($response)) {
            $order->update_meta_data('_wlc_lexware_credit_note_id', $response['id']);
            $order->update_meta_data('_wlc_lexware_invoice_voided', 'yes');
            $order->save();
            $order->add_order_note(
                sprintf(
                    __('Lexware Gutschrift erstellt: %s (ID: %s)', 'woo-lexware-connector'),
                    $response['voucherNumber'] ?? '',
                    $response['id']
                )
            );
        }
        return $response;
    }

    public function download_invoice_pdf($invoice_id) {
        $invoice = $this->request('GET', 'invoices/' . $invoice_id);
        if (is_wp_error($invoice)) {
            return $invoice;
        }
        if (empty($invoice['documentFileId'])) {
            return new WP_Error('no_pdf', __('PDF noch nicht verfügbar', 'woo-lexware-connector'));
        }
        $pdf_response = $this->request('GET', 'files/' . $invoice['documentFileId'], null, true);
        if (is_wp_error($pdf_response)) {
            return $pdf_response;
        }
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/lexware-invoices';
        $filename = 'invoice_' . $invoice_id . '.pdf';
        $filepath = $pdf_dir . '/' . $filename;
        file_put_contents($filepath, $pdf_response);
        return $filepath;
    }

    private function format_address($order) {
        $address = array(
            'name' => $order->get_formatted_billing_full_name(),
            'street' => $order->get_billing_address_1(),
            'zip' => $order->get_billing_postcode(),
            'city' => $order->get_billing_city(),
            'countryCode' => $order->get_billing_country()
        );
        if ($order->get_billing_address_2()) {
            $address['supplement'] = $order->get_billing_address_2();
        }
        if ($order->get_billing_company()) {
            $address['company'] = $order->get_billing_company();
        }
        return $address;
    }

    private function format_line_items($order, $negative = false) {
        $line_items = array();
        $multiplier = $negative ? -1 : 1;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $tax_class = $product ? $product->get_tax_class() : '';
            $tax_rate = $this->get_tax_rate_for_class($tax_class, $order);
            $line_items[] = array(
                'type' => 'custom',
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity() * $multiplier,
                'unitName' => 'Stück',
                'unitPrice' => array(
                    'currency' => $order->get_currency(),
                    'netAmount' => round($order->get_item_subtotal($item, false), 2) * $multiplier,
                    'taxRatePercentage' => $tax_rate
                )
            );
        }
        if (get_option('wlc_shipping_as_line_item', 'yes') === 'yes' && $order->get_shipping_total() > 0) {
            $shipping_tax_rate = $this->calculate_shipping_tax_rate($order);
            $line_items[] = array(
                'type' => 'custom',
                'name' => 'Versandkosten',
                'quantity' => 1 * $multiplier,
                'unitName' => 'Pauschal',
                'unitPrice' => array(
                    'currency' => $order->get_currency(),
                    'netAmount' => round($order->get_shipping_total(), 2) * $multiplier,
                    'taxRatePercentage' => $shipping_tax_rate
                )
            );
        }
        return $line_items;
    }

    private function get_tax_rate_for_class($tax_class, $order) {
        $tax_rates = WC_Tax::get_rates($tax_class, $order->get_billing_country());
        if (!empty($tax_rates)) {
            $rate = reset($tax_rates);
            return (float) $rate['rate'];
        }
        return 19.0; // Standard-MwSt Deutschland
    }

    private function calculate_shipping_tax_rate($order) {
        if ($order->get_shipping_tax() > 0 && $order->get_shipping_total() > 0) {
            $rate = ($order->get_shipping_tax() / $order->get_shipping_total()) * 100;
            return round($rate, 2);
        }
        return 19.0;
    }

    private function request($method, $endpoint, $data = null, $raw_response = false) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('Kein API-Key konfiguriert', 'woo-lexware-connector'));
        }
        $url = self::API_BASE_URL . $endpoint;
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => $raw_response ? 'application/pdf' : 'application/json'
            ),
            'timeout' => 30
        );
        if ($data !== null && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = json_encode($data);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $this->log_error('API Request Failed', $response->get_error_message());
            return $response;
        }
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if (get_option('wlc_enable_logging', 'yes') === 'yes') {
            $this->log_request($method, $endpoint, $data, $status_code, $body);
        }
        if ($status_code < 200 || $status_code >= 300) {
            $error_data = json_decode($body, true);
            $error_message = $error_data['message'] ?? $body;
            $this->log_error('API Error', $error_message, array(
                'status' => $status_code,
                'endpoint' => $endpoint,
                'request_data' => $data
            ));
            return new WP_Error('api_error', $error_message, array('status' => $status_code));
        }
        if ($raw_response) {
            return $body;
        }
        return json_decode($body, true);
    }

    private function log_request($method, $endpoint, $data, $status, $response) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'method' => $method,
            'endpoint' => $endpoint,
            'request_data' => $data,
            'status_code' => $status,
            'response' => substr($response, 0, 500)
        );
        $logs = get_option('wlc_api_logs', array());
        array_unshift($logs, $log_entry);
        $logs = array_slice($logs, 0, 100);
        update_option('wlc_api_logs', $logs);
    }

    private function log_error($title, $message, $context = array()) {
        $error_entry = array(
            'timestamp' => current_time('mysql'),
            'title' => $title,
            'message' => $message,
            'context' => $context
        );
        $errors = get_option('wlc_error_logs', array());
        array_unshift($errors, $error_entry);
        $errors = array_slice($errors, 0, 50);
        update_option('wlc_error_logs', $errors);
        if (get_option('wlc_email_on_error', 'yes') === 'yes') {
            $admin_email = get_option('admin_email');
            wp_mail($admin_email,'[WooCommerce Lexware Connector] Fehler',sprintf("Fehler: %s\n\nNachricht: %s\n\nZeit: %s", $title, $message, current_time('mysql')));
        }
    }

    private function get_payment_terms_for_order($order) {
        $payment_method = $order->get_payment_method();
        $specific_terms = get_option('wlc_payment_terms_' . $payment_method, '');
        if (!empty($specific_terms)) {
            return $specific_terms;
        }
        return get_option('wlc_payment_terms', 'Zahlbar innerhalb von 14 Tagen ohne Abzug.');
    }

    private function get_payment_due_days_for_order($order) {
        $payment_method = $order->get_payment_method();
        $specific_days = get_option('wlc_payment_due_days_' . $payment_method, '');
        if ($specific_days !== '' && $specific_days !== false) {
            return (int) $specific_days;
        }
        return (int) get_option('wlc_payment_due_days', 14);
    }

    /**
     * Ersetzt Shortcodes im Rechnungstext durch Werte aus Bestellung
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

}
