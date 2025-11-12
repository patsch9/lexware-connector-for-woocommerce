<?php
/**
 * Queue Handler
 * Verwaltet die Warteschlange für API-Aufrufe
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_Queue_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Cron-Job registrieren
        add_action('wlc_process_queue', array($this, 'process_queue'));
        
        if (!wp_next_scheduled('wlc_process_queue')) {
            wp_schedule_event(time(), 'every_minute', 'wlc_process_queue');
        }
        
        // Eigenes Cron-Intervall
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }
    
    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => esc_html__('Jede Minute', 'lexware-connector-for-woocommerce')
        );
        return $schedules;
    }
    
    public static function add_to_queue($order_id, $action) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wlc_queue';
        
        // Prüfe ob bereits in Queue (DirectDB ok für Queue-System)
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . esc_sql($table_name) . " WHERE order_id = %d AND action = %s AND status = 'pending'",
            $order_id,
            $action
        ));
        
        if ($exists > 0) {
            return false;
        }
        
        $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order_id,
                'action' => $action,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%d', '%s')
        );
        
        return true;
    }
    
    public function process_queue() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wlc_queue';
        $max_attempts = (int) get_option('wlc_retry_attempts', 3);
        
        // Hole nächstes pending Item (DirectDB ok für Queue-System)
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . esc_sql($table_name) . " WHERE status = 'pending' AND attempts < %d ORDER BY created_at ASC LIMIT 1",
                $max_attempts
            )
        );
        
        if (!$item) {
            return;
        }
        
        $this->process_item($item);
    }
    
    public static function process_next_item() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wlc_queue';
        $max_attempts = (int) get_option('wlc_retry_attempts', 3);
        
        // DirectDB ok für Queue-System
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . esc_sql($table_name) . " WHERE status = 'pending' AND attempts < %d ORDER BY created_at ASC LIMIT 1",
                $max_attempts
            )
        );
        
        if (!$item) {
            return new WP_Error('no_items', esc_html__('Keine Items in Queue', 'lexware-connector-for-woocommerce'));
        }
        
        $instance = self::get_instance();
        return $instance->process_item($item);
    }
    
    private function process_item($item) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wlc_queue';
        $order = wc_get_order($item->order_id);
        
        if (!$order) {
            $this->mark_as_failed($item->id, esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce'));
            return new WP_Error('order_not_found', esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce'));
        }
        
        $api_client = new WLC_Lexware_API_Client();
        
        // Erhöhe Attempt-Counter (DirectDB ok für Queue-System)
        $wpdb->update(
            $table_name,
            array('attempts' => $item->attempts + 1),
            array('id' => $item->id),
            array('%d'),
            array('%d')
        );
        
        $result = null;
        
        switch ($item->action) {
            case 'create_invoice':
                $result = $this->handle_create_invoice($order, $api_client, $item);
                break;
                
            case 'void_invoice':
                $result = $this->handle_void_invoice($order, $api_client, $item);
                break;
                
            case 'update_invoice':
                $result = $this->handle_update_invoice($order, $api_client, $item);
                break;
        }
        
        if (is_wp_error($result)) {
            $this->mark_as_failed($item->id, $result->get_error_message());
            return $result;
        } else {
            $this->mark_as_completed($item->id, $result);
            
            // Automatischer E-Mail-Versand nach erfolgreicher Rechnungserstellung
            if ($item->action === 'create_invoice' && get_option('wlc_auto_send_email', 'no') === 'yes') {
                $this->send_invoice_email($order);
            }
            
            return true;
        }
    }
    
    private function handle_create_invoice($order, $api_client, $item) {
        // Sync Kontakt
        if (get_option('wlc_auto_sync_contacts', 'yes') === 'yes') {
            $contact_result = $api_client->sync_contact($order);
            
            if (is_wp_error($contact_result)) {
                return $contact_result;
            }
            
            $contact_id = $contact_result['id'];
        } else {
            $contact_id = null;
        }
        
        // Erstelle Rechnung
        $invoice_result = $api_client->create_invoice($order, $contact_id);
        
        if (is_wp_error($invoice_result)) {
            return $invoice_result;
        }
        
        return $invoice_result['id'];
    }
    
    private function handle_void_invoice($order, $api_client, $item) {
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        
        if (!$lexware_invoice_id) {
            return new WP_Error('no_invoice', esc_html__('Keine Rechnung vorhanden', 'lexware-connector-for-woocommerce'));
        }
        
        $credit_note_result = $api_client->create_credit_note($order, $lexware_invoice_id);
        
        if (is_wp_error($credit_note_result)) {
            return $credit_note_result;
        }
        
        return $credit_note_result['id'];
    }
    
    private function handle_update_invoice($order, $api_client, $item) {
        // Storniere alte Rechnung
        $void_result = $this->handle_void_invoice($order, $api_client, $item);
        
        if (is_wp_error($void_result)) {
            return $void_result;
        }
        
        // Erstelle neue Rechnung
        $order->delete_meta_data('_wlc_lexware_invoice_id');
        $order->delete_meta_data('_wlc_lexware_invoice_voided');
        $order->save();
        
        return $this->handle_create_invoice($order, $api_client, $item);
    }
    
    private function send_invoice_email($order) {
        // Prüfe ob WooCommerce Mailer verfügbar ist
        if (!function_exists('WC')) {
            return;
        }
        
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        
        // Prüfe ob E-Mail-Klasse registriert ist
        if (!isset($emails['WLC_Invoice_Email'])) {
            return;
        }
        
        // Sende E-Mail
        $emails['WLC_Invoice_Email']->trigger($order->get_id(), $order);
        
        // Order-Note hinzufügen
        $order->add_order_note(esc_html__('Rechnung automatisch per E-Mail versendet', 'lexware-connector-for-woocommerce'));
    }
    
    private function mark_as_completed($item_id, $result_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wlc_queue';
        
        // DirectDB ok für Queue-System
        $wpdb->update(
            $table_name,
            array(
                'status' => 'completed',
                'lexware_invoice_id' => $result_id,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $item_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
    
    private function mark_as_failed($item_id, $error_message) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wlc_queue';
        
        // DirectDB ok für Queue-System
        $wpdb->update(
            $table_name,
            array(
                'status' => 'failed',
                'error_message' => $error_message,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $item_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
    
    public static function get_queue_status() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wlc_queue';
        
        // DirectDB ok für Queue-System
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . esc_sql($table_name) . " WHERE status IN (%s, %s) ORDER BY created_at DESC LIMIT 50",
                'pending',
                'failed'
            )
        );
    }
}
