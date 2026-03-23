<?php
/**
 * Queue Handler
 * Verwaltet die Warteschlange für API-Aufrufe mit Action Scheduler
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
        // Action Hook für Queue-Verarbeitung registrieren
        add_action('wlc_process_queue', array($this, 'process_queue'));
        
        // WICHTIG: Setup Scheduler NACH Action Scheduler Init
        // action_scheduler_init ist der richtige Hook (Priority 20 um sicher zu sein)
        add_action('action_scheduler_init', array($this, 'setup_scheduler'), 20);
        
        // Cleanup bei Plugin-Deaktivierung
        add_action('wlc_cleanup_scheduler', array($this, 'cleanup_scheduler'));
    }
    
    /**
     * Setup Action Scheduler für Queue-Verarbeitung
     * Wird aufgerufen NACHDEM Action Scheduler vollständig initialisiert ist
     */
    public function setup_scheduler() {
        // Prüfe ob Action Scheduler verfügbar ist
        if (!function_exists('as_schedule_recurring_action')) {
            // Fallback auf WP Cron wenn Action Scheduler nicht verfügbar
            $this->setup_fallback_cron();
            return;
        }
        
        // Prüfe ob bereits ein recurring action scheduled ist
        if (!as_has_scheduled_action('wlc_process_queue')) {
            // Schedule recurring action alle 60 Sekunden
            as_schedule_recurring_action(
                time(),                    // Start sofort
                60,                        // Alle 60 Sekunden
                'wlc_process_queue',      // Hook name
                array(),                   // Args
                '',                        // Group (leer = default)
                true                       // Unique (verhindert Duplikate)
            );
            
            // Log für Debugging
            if (get_option('wlc_enable_logging', 'yes') === 'yes') {
                error_log('WLC: Action Scheduler recurring action registriert');
            }
        }
    }
    
    /**
     * Fallback auf WordPress Cron wenn Action Scheduler nicht verfügbar
     */
    private function setup_fallback_cron() {
        // Eigenes Cron-Intervall registrieren
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Schedule WP Cron Event wenn noch nicht scheduled
        if (!wp_next_scheduled('wlc_process_queue')) {
            wp_schedule_event(time(), 'every_minute', 'wlc_process_queue');
            
            if (get_option('wlc_enable_logging', 'yes') === 'yes') {
                error_log('WLC: Fallback WP Cron registriert (Action Scheduler nicht verfügbar)');
            }
        }
    }
    
    /**
     * Füge Custom Cron-Intervall hinzu (Fallback)
     */
    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => esc_html__('Jede Minute', 'lexware-connector-for-woocommerce')
        );
        return $schedules;
    }
    
    /**
     * Cleanup Scheduler bei Plugin-Deaktivierung
     */
    public function cleanup_scheduler() {
        // Unschedule Action Scheduler Actions
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wlc_process_queue');
        }
        
        // Unschedule WP Cron (Fallback)
        $timestamp = wp_next_scheduled('wlc_process_queue');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wlc_process_queue');
        }
        
        // Clear alle scheduled actions
        wp_clear_scheduled_hook('wlc_process_queue');
    }
    
    /**
     * Füge Item zur Queue hinzu
     */
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
        
        $inserted = $wpdb->insert(
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
        
        if ($inserted && get_option('wlc_enable_logging', 'yes') === 'yes') {
            error_log(sprintf('WLC: Item zur Queue hinzugefügt - Order #%d, Action: %s', $order_id, $action));
        }
        
        return (bool) $inserted;
    }
    
    /**
     * Verarbeite Queue (wird von Action Scheduler/Cron aufgerufen)
     */
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
            // Keine Items in Queue - das ist OK
            return;
        }
        
        if (get_option('wlc_enable_logging', 'yes') === 'yes') {
            error_log(sprintf('WLC: Verarbeite Queue-Item #%d - Order #%d, Action: %s, Attempt: %d', 
                $item->id, $item->order_id, $item->action, $item->attempts + 1));
        }
        
        $this->process_item($item);
    }
    
    /**
     * Verarbeite nächstes Item (für manuelle Verarbeitung)
     */
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
    
    /**
     * Verarbeite einzelnes Queue-Item
     */
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
        
        if (get_option('wlc_enable_logging', 'yes') === 'yes') {
            error_log(sprintf('WLC: Queue-Item #%d erfolgreich verarbeitet', $item_id));
        }
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
        
        if (get_option('wlc_enable_logging', 'yes') === 'yes') {
            error_log(sprintf('WLC: Queue-Item #%d fehlgeschlagen: %s', $item_id, $error_message));
        }
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
    
    /**
     * Debug-Info für Action Scheduler Status
     */
    public static function get_scheduler_status() {
        $status = array(
            'type' => 'unknown',
            'scheduled' => false,
            'next_run' => null
        );
        
        // Prüfe Action Scheduler
        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action('wlc_process_queue');
            if ($next) {
                $status['type'] = 'action_scheduler';
                $status['scheduled'] = true;
                $status['next_run'] = date('Y-m-d H:i:s', $next);
            }
        }
        
        // Fallback: WP Cron
        if (!$status['scheduled']) {
            $next = wp_next_scheduled('wlc_process_queue');
            if ($next) {
                $status['type'] = 'wp_cron';
                $status['scheduled'] = true;
                $status['next_run'] = date('Y-m-d H:i:s', $next);
            }
        }
        
        return $status;
    }
}
