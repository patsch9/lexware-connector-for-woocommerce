<?php
/**
 * WooCommerce E-Mail für Lexware Rechnungen
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prüfe ob WC_Email verfügbar ist
if (!class_exists('WC_Email')) {
    return;
}

class WLC_Invoice_Email extends WC_Email {

    private $pdf_path = null;

    public function __construct() {
        $this->id = 'wlc_invoice';
        $this->customer_email = true;
        $this->title = __('Lexware Rechnung', 'lexware-connector-for-woocommerce');
        $this->description = __('E-Mail mit Rechnung als PDF-Anhang', 'lexware-connector-for-woocommerce');
        $this->template_html = 'emails/customer-invoice.php';
        $this->template_plain = 'emails/plain/customer-invoice.php';
        $this->template_base = WLC_PLUGIN_DIR . 'templates/';
        $this->placeholders = array(
            '{order_date}' => '',
            '{order_number}' => '',
        );

        parent::__construct();
    }

    public function get_default_subject() {
        return __('Ihre Rechnung für Bestellung {order_number}', 'lexware-connector-for-woocommerce');
    }

    public function get_default_heading() {
        return __('Rechnung für Ihre Bestellung', 'lexware-connector-for-woocommerce');
    }

    public function trigger($order_id, $order = null) {
        $this->setup_locale();

        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if (!is_a($order, 'WC_Order')) {
            $this->restore_locale();
            return;
        }

        $this->object = $order;
        $this->recipient = $order->get_billing_email();
        $this->placeholders['{order_date}'] = wc_format_datetime($order->get_date_created());
        $this->placeholders['{order_number}'] = $order->get_order_number();

        // PDF vorbereiten
        $this->pdf_path = null;
        $invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        
        if ($invoice_id) {
            $api_client = new WLC_Lexware_API_Client();
            $pdf_path = $api_client->download_invoice_pdf($invoice_id);
            
            if (!is_wp_error($pdf_path) && file_exists($pdf_path)) {
                $this->pdf_path = $pdf_path;
            }
        }

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }

        $this->restore_locale();
    }

    /**
     * Überschreibe get_attachments() um PDF dynamisch hinzuzufügen
     */
    public function get_attachments() {
        $attachments = parent::get_attachments();
        
        if (!is_array($attachments)) {
            $attachments = array();
        }
        
        // Füge PDF hinzu, falls vorhanden
        if ($this->pdf_path && file_exists($this->pdf_path)) {
            $attachments[] = $this->pdf_path;
        }
        
        return apply_filters('woocommerce_email_attachments', $attachments, $this->id, $this->object, $this);
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
            ),
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => true,
                'email' => $this,
            ),
            '',
            $this->template_base
        );
    }
}
