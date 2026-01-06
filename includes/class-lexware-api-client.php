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
    const RATE_LIMIT_REQUESTS = 2; // 2 requests pro Sekunde
    const RATE_LIMIT_WINDOW = 1; // 1 Sekunde
    const MAX_RETRIES = 3; // Exponential Backoff: max 3 Retries
    
    private $api_key;
    private $last_request_time = 0;
    private $request_times = array(); // Für Tracking der Requests im Zeitfenster

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

    /**
     * Respektiert Rate Limits und wartet bei Bedarf
     */
    private function enforce_rate_limit() {
        $now = microtime(true);
        
        // Entferne alte Einträge außerhalb des Zeitfensters
        $this->request_times = array_filter($this->request_times, function($time) use ($now) {
            return ($now - $time) < self::RATE_LIMIT_WINDOW;
        });
        
        // Wenn wir das Limit erreicht haben, warte
        if (count($this->request_times) >= self::RATE_LIMIT_REQUESTS) {
            // Warte auf den ältesten Request
            $oldest = min($this->request_times);
            $wait_time = self::RATE_LIMIT_WINDOW - ($now - $oldest) + 0.01; // 10ms Puffer
            
            if ($wait_time > 0) {
                usleep($wait_time * 1000000); // Convert to microseconds
                $this->request_times = array(); // Reset nach Warten
            }
        }
        
        // Tracke diesen Request
        $this->request_times[] = microtime(true);
    }

    /**
     * Prüft, ob ein Coupon ein Wertgutschein ist
     * Ein Wertgutschein wird in WooCommerce mit einem separaten Flag gespeichert
     */
    private function is_value_voucher($coupon_code) {
        $coupon = new WC_Coupon($coupon_code);
        if (!$coupon || !$coupon->get_id()) {
            return false;
        }
        
        // Prüfe verschiedene Möglichkeiten, wie WooCommerce Wertgutscheine kennzeichnet
        // 1. Coupon-Meta (falls vom Plugin gespeichert)
        $is_value_voucher = get_post_meta($coupon->get_id(), '_is_value_voucher', true);
        if ($is_value_voucher === 'yes') {
            return true;
        }
        
        // 2. Prüfe WooCommerce-Coupon Meta direkt
        $coupon_data = get_post_meta($coupon->get_id());
        if (isset($coupon_data['_is_value_voucher']) && $coupon_data['_is_value_voucher'][0] === 'yes') {
            return true;
        }
        
        return false;
    }

public function sync_contact($order) {
    $existing_contact_id = $order->get_meta('_wlc_lexware_contact_id');
    
    // Kontaktdaten vorbereiten
    $contact_data = array(
        'version' => 0,
        'roles' => array(
            'customer' => new stdClass()
        )
    );
    
    $billing_company = $order->get_billing_company();
    $is_company = !empty($billing_company);
    
    if ($is_company) {
        // Firmen-Kontakt - Basis
        $company_data = array(
            'name' => $billing_company,
            'contactPersons' => array(
                array(
                    'firstName' => $order->get_billing_first_name(),
                    'lastName' => $order->get_billing_last_name(),
                    'emailAddress' => $order->get_billing_email(),
                    'phoneNumber' => $order->get_billing_phone() ?: ''
                )
            )
        );
        
        // Nur hinzufügen wenn Wert vorhanden
        $tax_number = $order->get_meta('_billing_tax_number');
        if (!empty($tax_number)) {
            $company_data['taxNumber'] = $tax_number;
        }
        
        $vat_id = $order->get_meta('_billing_vat_id');
        if (!empty($vat_id)) {
            $company_data['vatRegistrationId'] = $vat_id;
            $company_data['allowTaxFreeInvoices'] = true;
        }
        
        $contact_data['company'] = $company_data;
    } else {
        // Privatkunden-Kontakt
        $contact_data['person'] = array(
            'firstName' => $order->get_billing_first_name(),
            'lastName' => $order->get_billing_last_name()
        );
    }
    
    // Adressen
    $contact_data['addresses'] = array(
        'billing' => array(
            array(
                'street' => $order->get_billing_address_1(),
                'zip' => $order->get_billing_postcode(),
                'city' => $order->get_billing_city(),
                'countryCode' => $order->get_billing_country()
            )
        )
    );
    
    // Supplement nur wenn vorhanden
    if ($order->get_billing_address_2()) {
        $contact_data['addresses']['billing'][0]['supplement'] = $order->get_billing_address_2();
    }
    
    // E-Mail-Adressen
    if ($order->get_billing_email()) {
        $contact_data['emailAddresses'] = array(
            'business' => array($order->get_billing_email())
        );
    }
    
    // Telefonnummern
    if ($order->get_billing_phone()) {
        $contact_data['phoneNumbers'] = array(
            'business' => array($order->get_billing_phone())
        );
    }
    
    // Kontakt erstellen oder aktualisieren
    if ($existing_contact_id) {
        $endpoint = 'contacts/' . $existing_contact_id;
        $existing = $this->request('GET', $endpoint);
        if (!is_wp_error($existing) && isset($existing['version'])) {
            $contact_data['version'] = $existing['version'];
        }
        $result = $this->request('PUT', $endpoint, $contact_data);
    } else {
        $result = $this->request('POST', 'contacts', $contact_data);
    }
    
    if (is_wp_error($result)) {
        return $result;
    }
    
    $contact_id = $result['id'];
    $order->update_meta_data('_wlc_lexware_contact_id', $contact_id);
    $order->save();
    
    return $result;
}


    public function create_invoice($order, $contact_id) {
        $finalize = get_option('wlc_finalize_immediately', 'yes') === 'yes';
        $order_date = $order->get_date_created();
        $voucher_date = $this->format_lexware_date($order_date->getTimestamp());
        $is_company = !empty($order->get_billing_company());
        $tax_type = $is_company ? 'net' : 'gross';
        
        $invoice_data = array(
            'voucherDate' => $voucher_date,
            'address' => $this->format_address($order),
            'lineItems' => $this->format_line_items($order),
            'totalPrice' => array('currency' => $order->get_currency()),
            'taxConditions' => array('taxType' => $tax_type),
            'shippingConditions' => array('shippingDate' => $voucher_date, 'shippingType' => 'delivery'),
            'title' => $this->replace_shortcodes(get_option('wlc_invoice_title', 'Rechnung'), $order),
            'introduction' => $this->replace_shortcodes(get_option('wlc_invoice_introduction', 'Vielen Dank für Ihre Bestellung.'), $order),
            'remark' => $this->replace_shortcodes(get_option('wlc_closing_text', 'Vielen Dank für Ihr Vertrauen.'), $order)
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
        
        // Erstelle Rechnung
        $response = $this->request('POST', $endpoint, $invoice_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $invoice_id = $response['id'];
        $invoice_number = '';
        
        // Hole vollständige Rechnungsdaten mit Nummer (nach Finalisierung)
        if ($finalize) {
            for ($i = 0; $i < 5; $i++) {
                $invoice_details = $this->request('GET', 'invoices/' . $invoice_id);
                if (!is_wp_error($invoice_details) && isset($invoice_details['voucherNumber']) && $invoice_details['voucherNumber']) {
                    $invoice_number = $invoice_details['voucherNumber'];
                    break;
                }
                sleep(1);
            }
        }
        
        // Speichere Metadaten
        $order->update_meta_data('_wlc_lexware_invoice_id', $invoice_id);
        $order->update_meta_data('_wlc_lexware_invoice_number', $invoice_number);
        $order->save();
        
        // Notiz mit Rechnungsnummer
        $order->add_order_note(
            sprintf(
                /* translators: 1: Invoice number, 2: Invoice ID */
                __('[Lexware Rechnung erstellt: %1$s (ID: %2$s)]', 'lexware-connector-for-woocommerce'),
                $invoice_number ?: __('Entwurf', 'lexware-connector-for-woocommerce'),
                $invoice_id
            )
        );
        
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
            'title' => __('Gutschrift / Stornierung', 'lexware-connector-for-woocommerce'),
            /* translators: %s: Original invoice ID */
            'introduction' => sprintf(__('Gutschrift zur Rechnung (Lexware ID: %s)', 'lexware-connector-for-woocommerce'), $original_invoice_id)
        );
        
        $response = $this->request('POST', 'credit-notes?finalize=true', $credit_note_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $credit_note_id = $response['id'];
        $credit_note_number = '';
        
        // Hole vollständige Gutschriftsdaten (robustes Polling)
        for ($i = 0; $i < 5; $i++) {
            $credit_note_details = $this->request('GET', 'credit-notes/' . $credit_note_id);
            if (!is_wp_error($credit_note_details) && isset($credit_note_details['voucherNumber']) && $credit_note_details['voucherNumber']) {
                $credit_note_number = $credit_note_details['voucherNumber'];
                break;
            }
            sleep(1);
        }
        
        $order->update_meta_data('_wlc_lexware_credit_note_id', $credit_note_id);
        $order->update_meta_data('_wlc_lexware_invoice_voided', 'yes');
        $order->save();
        
        $order->add_order_note(
            sprintf(
                /* translators: 1: Credit note number, 2: Credit note ID */
                __('Lexware Gutschrift erstellt: %1$s (ID: %2$s)', 'lexware-connector-for-woocommerce'),
                $credit_note_number ?: __('Entwurf', 'lexware-connector-for-woocommerce'),
                $credit_note_id
            )
        );
        
        return $response;
    }

    public function download_invoice_pdf($invoice_id) {
        $invoice = $this->request('GET', 'invoices/' . $invoice_id);
        
        if (is_wp_error($invoice)) {
            return $invoice;
        }
        
        // Korrektur: documentFileId ist in ['files']['documentFileId']
        if (empty($invoice['files']['documentFileId'])) {
            return new WP_Error('no_pdf', __('PDF noch nicht verfügbar', 'lexware-connector-for-woocommerce'));
        }
        
        $pdf_response = $this->request('GET', 'files/' . $invoice['files']['documentFileId'], null, true);
        
        if (is_wp_error($pdf_response)) {
            return $pdf_response;
        }
        
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/lexware-invoices';
        
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }
        
        $filename = 'invoice_' . $invoice_id . '.pdf';
        $filepath = $pdf_dir . '/' . $filename;
        
        file_put_contents($filepath, $pdf_response);
        
        return $filepath;
    }

    private function format_address($order) {
        $company = $order->get_billing_company();
        
        $address = array(
            'street' => $order->get_billing_address_1(),
            'zip' => $order->get_billing_postcode(),
            'city' => $order->get_billing_city(),
            'countryCode' => $order->get_billing_country()
        );
        
        // Bei Firmenadressen: Firma als Hauptname, Person als Supplement
        if (!empty($company)) {
            $address['name'] = $company;
            $address['supplement'] = $order->get_formatted_billing_full_name();
        } else {
            // Bei Privatpersonen: Nur der Name
            $address['name'] = $order->get_formatted_billing_full_name();
        }
        
        // Adresszusatz (falls vorhanden)
        if ($order->get_billing_address_2()) {
            // Falls bereits supplement durch Firma gesetzt, anhängen
            if (isset($address['supplement'])) {
                $address['supplement'] .= ', ' . $order->get_billing_address_2();
            } else {
                $address['supplement'] = $order->get_billing_address_2();
            }
        }
        
        return $address;
    }

    private function format_line_items($order, $negative = false) {
        $line_items = array();
        $multiplier = $negative ? -1 : 1;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $tax_class = $product ? $product->get_tax_class() : '';
            $tax_rate = $this->get_tax_rate_for_class($tax_class, $order, $item);
            
            $net_amount = round($order->get_item_subtotal($item, false), 2) * $multiplier;
            $gross_amount = round($order->get_item_subtotal($item, true), 2) * $multiplier;
            
            $line_items[] = array(
                'type' => 'custom',
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity() * $multiplier,
                'unitName' => __('Stück', 'lexware-connector-for-woocommerce'),
                'unitPrice' => array(
                    'currency' => $order->get_currency(),
                    'netAmount' => $net_amount,
                    'grossAmount' => $gross_amount,
                    'taxRatePercentage' => $tax_rate
                )
            );
        }
        
        // Versandkosten als Line Item (falls aktiviert)
        if (get_option('wlc_shipping_as_line_item', 'yes') === 'yes' && $order->get_shipping_total() > 0) {
            $shipping_tax_rate = $this->calculate_shipping_tax_rate($order);
            $shipping_net = round($order->get_shipping_total(), 2) * $multiplier;
            $shipping_gross = round($order->get_shipping_total() + $order->get_shipping_tax(), 2) * $multiplier;
            
            $line_items[] = array(
                'type' => 'custom',
                'name' => __('Versandkosten', 'lexware-connector-for-woocommerce'),
                'quantity' => 1 * $multiplier,
                'unitName' => __('Pauschal', 'lexware-connector-for-woocommerce'),
                'unitPrice' => array(
                    'currency' => $order->get_currency(),
                    'netAmount' => $shipping_net,
                    'grossAmount' => $shipping_gross,
                    'taxRatePercentage' => $shipping_tax_rate
                )
            );
        }
        
        // Gutscheine / Coupons als Rabatte (negative Line Items)
        $coupon_codes = $order->get_coupon_codes();
        if (!empty($coupon_codes)) {
            // Berechne durchschnittlichen Steuersatz aller Artikel EINMAL
            $average_tax_rate = $this->get_average_tax_rate_for_items($order);
            
            foreach ($coupon_codes as $coupon_code) {
                $coupon = new WC_Coupon($coupon_code);
                
                if ($coupon && $coupon->get_id()) {
                    $coupon_discount = 0;
                    
                    foreach ($order->get_items('coupon') as $coupon_item) {
                        if ($coupon_item->get_code() === $coupon_code) {
                            // Hole Discount von diesem Item (Bruttobetrag aus WooCommerce)
                            $coupon_discount = abs($coupon_item->get_discount());
                            
                            // WICHTIG: Prüfe, ob es ein Wertgutschein ist
                            $is_value_voucher = $this->is_value_voucher($coupon_code);
                            
                            if ($is_value_voucher) {
                                // Wertgutscheine: OHNE Steuer (0%)
                                // Sie werden bei Einlösung besteuert, nicht bei Verkauf
                                $discount_tax_rate = 0; // KEINE Steuer für Wertgutscheine!
                                $discount_gross = round($coupon_discount, 2);
                                $discount_net = $discount_gross; // Bei 0% Steuer: netto = brutto
                            } else {
                                // Normale Rabatte: Mit Steuer der Artikel
                                $discount_tax_rate = $average_tax_rate;
                                $discount_gross = round($coupon_discount, 2);
                                $discount_net = round($coupon_discount / (1 + ($discount_tax_rate / 100)), 2);
                            }
                            
                            // Für negative Items (Rabatte) multiplizieren wir mit -1
                            $line_items[] = array(
                                'type' => 'custom',
                                'name' => sprintf(
                                    $is_value_voucher 
                                        ? __('Wertgutschein: %s', 'lexware-connector-for-woocommerce')
                                        : __('Rabatt: %s', 'lexware-connector-for-woocommerce'),
                                    $coupon_code
                                ),
                                'quantity' => -1 * $multiplier,
                                'unitName' => __('Pauschal', 'lexware-connector-for-woocommerce'),
                                'unitPrice' => array(
                                    'currency' => $order->get_currency(),
                                    'netAmount' => $discount_net * $multiplier,
                                    'grossAmount' => $discount_gross * $multiplier,
                                    'taxRatePercentage' => $discount_tax_rate
                                )
                            );
                            break;
                        }
                    }
                }
            }
        }
        
        return $line_items;
    }

    private function get_tax_rate_for_class($tax_class, $order, $item = null) {
        // Robuste Ermittlung der Steuer für das Line Item
        if ($item && is_callable([$item, 'get_taxes'])) {
            $taxes = $item->get_taxes();
            // WooCommerce liefert ein Array mit 'total' => [tax_id => amount]
            if (is_array($taxes) && isset($taxes['total']) && is_array($taxes['total'])) {
                foreach ($taxes['total'] as $tax_id => $tax_amount) {
                    if ($tax_amount > 0) {
                        $rate_data = WC_Tax::_get_tax_rate($tax_id);
                        if (isset($rate_data['tax_rate'])) {
                            return (float)$rate_data['tax_rate'];
                        }
                    }
                }
            }
        }
        // Fallback: Berechne Steuersatz aus Gesamtsteuer
        $total_tax = $order->get_total_tax();
        $subtotal = $order->get_subtotal();
        if ($subtotal > 0 && $total_tax > 0) {
            return round(($total_tax / $subtotal) * 100, 2);
        }
        return 19.0;
    }

    private function get_average_tax_rate_for_items($order) {
        // Berechne den durchschnittlichen Steuersatz basierend auf allen Artikeln
        if ($order->get_subtotal() > 0) {
            $total_tax = $order->get_total_tax();
            $items_subtotal = $order->get_subtotal();
            if ($items_subtotal > 0 && $total_tax > 0) {
                $rate = ($total_tax / $items_subtotal) * 100;
                return round($rate, 2);
            }
        }
        return 19.0; // Fallback auf 19%
    }

    private function calculate_shipping_tax_rate($order) {
        if ($order->get_shipping_tax() > 0 && $order->get_shipping_total() > 0) {
            $rate = ($order->get_shipping_tax() / $order->get_shipping_total()) * 100;
            return round($rate, 2);
        }
        return 19.0;
    }

    private function get_payment_terms_for_order($order) {
        $payment_method = $order->get_payment_method();
        $specific_terms = get_option('wlc_payment_terms_' . $payment_method, '');
        if (!empty($specific_terms)) {
            return $specific_terms;
        }
        return __('Zahlbar innerhalb von 14 Tagen ohne Abzug.', 'lexware-connector-for-woocommerce');
    }

    private function get_payment_due_days_for_order($order) {
        $payment_method = $order->get_payment_method();
        $specific_days = get_option('wlc_payment_due_days_' . $payment_method, '');
        if ($specific_days !== '' && $specific_days !== false) {
            return (int) $specific_days;
        }
        return (int) get_option('wlc_payment_due_days', 14);
    }

    public function replace_shortcodes($text, $order) {
        if (!$order) return $text;
        
        // Formatiere Preis ohne HTML
        $total_formatted = number_format_i18n($order->get_total(), 2) . ' ' . $order->get_currency();
        
        $replace = array(
            '[order_number]'     => $order->get_order_number(),
            '[order_date]'       => date_i18n(get_option('date_format'), strtotime($order->get_date_created())),
            '[customer_name]'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '[customer_company]' => $order->get_billing_company(),
            '[total]'            => $total_formatted,
            '[payment_method]'   => $order->get_payment_method_title(),
        );
        
        return strtr($text, $replace);
    }

    private function request($method, $endpoint, $data = null, $raw_response = false) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('Kein API-Key konfiguriert', 'lexware-connector-for-woocommerce'));
        }
        
        // Enforce rate limiting
        $this->enforce_rate_limit();
        
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
        
        // Exponential Backoff bei 429 Responses
        $retry_count = 0;
        $response = null;
        
        while ($retry_count <= self::MAX_RETRIES) {
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                $this->log_error('API Request Failed', $response->get_error_message());
                return $response;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            
            // Bei Rate Limit: exponential backoff
            if ($status_code == 429) {
                if ($retry_count < self::MAX_RETRIES) {
                    $wait_time = pow(2, $retry_count); // 1s, 2s, 4s
                    $this->log_error('Rate Limited', 'HTTP 429, waiting ' . $wait_time . 's before retry');
                    sleep($wait_time);
                    $retry_count++;
                    continue;
                } else {
                    $body = wp_remote_retrieve_body($response);
                    $this->log_error('Rate Limit Exceeded', 'Max retries reached for Rate Limiting');
                    return new WP_Error('rate_limit', __('Lexware API Rate Limit überschritten - bitte später erneut versuchen', 'lexware-connector-for-woocommerce'));
                }
            }
            
            // Success or other error
            break;
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
            if (is_email($admin_email)) {
                wp_mail($admin_email,'[WooCommerce Lexware Connector] Fehler',sprintf("Fehler: %s\n\nNachricht: %s\n\nZeit: %s", $title, $message, current_time('mysql')));
            }
        }
    }
}
