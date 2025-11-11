<?php
/**
 * Customer Area Integration
 * Zeigt Rechnungen im WooCommerce Kundenbereich an
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_Customer_Area {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        if (get_option('wlc_show_in_customer_area', 'yes') !== 'yes') {
            return;
        }
        
        // Füge Download-Button in Order-Details hinzu
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_invoice_download_button'), 10, 1);
        
        // Füge Spalte in "Meine Bestellungen" hinzu
        add_filter('woocommerce_my_account_my_orders_columns', array($this, 'add_orders_column'));
        add_action('woocommerce_my_account_my_orders_column_invoice', array($this, 'render_orders_column'));
        
        // Download-Handler
        add_action('init', array($this, 'handle_invoice_download'));
    }
    
    public function add_invoice_download_button($order) {
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        
        if (!$lexware_invoice_id || $invoice_voided === 'yes') {
            return;
        }
        
        $download_url = $this->get_download_url($order->get_id());
        
        ?>
        <section class="wlc-invoice-download" style="margin-top: 20px;">
            <h2><?php _e('Rechnung', 'woo-lexware-connector'); ?></h2>
            <p>
                <a href="<?php echo esc_url($download_url); ?>" 
                   class="button" 
                   target="_blank">
                    <?php _e('Rechnung herunterladen (PDF)', 'woo-lexware-connector'); ?>
                </a>
            </p>
        </section>
        <?php
    }
    
    public function add_orders_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $name) {
            $new_columns[$key] = $name;
            
            if ($key === 'order-total') {
                $new_columns['invoice'] = __('Rechnung', 'woo-lexware-connector');
            }
        }
        
        return $new_columns;
    }
    
    public function render_orders_column($order) {
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        
        if ($lexware_invoice_id && $invoice_voided !== 'yes') {
            $download_url = $this->get_download_url($order->get_id());
            echo '<a href="' . esc_url($download_url) . '" target="_blank">' . __('PDF', 'woo-lexware-connector') . '</a>';
        } else {
            echo '–';
        }
    }
    
    public function handle_invoice_download() {
        if (!isset($_GET['wlc_download_invoice']) || !isset($_GET['order_id']) || !isset($_GET['nonce'])) {
            return;
        }
        
        $order_id = intval($_GET['order_id']);
        $nonce = sanitize_text_field($_GET['nonce']);
        
        if (!wp_verify_nonce($nonce, 'wlc_download_' . $order_id)) {
            wp_die(__('Ungültiger Sicherheitsschlüssel', 'woo-lexware-connector'));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die(__('Bestellung nicht gefunden', 'woo-lexware-connector'));
        }
        
        // Prüfe ob User berechtigt ist
        if (!current_user_can('view_order', $order_id)) {
            wp_die(__('Keine Berechtigung', 'woo-lexware-connector'));
        }
        
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        
        if (!$lexware_invoice_id) {
            wp_die(__('Keine Rechnung vorhanden', 'woo-lexware-connector'));
        }
        
        // Lade PDF herunter
        $api_client = new WLC_Lexware_API_Client();
        $pdf_path = $api_client->download_invoice_pdf($lexware_invoice_id);
        
        if (is_wp_error($pdf_path)) {
            wp_die($pdf_path->get_error_message());
        }
        
        // Sende PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="rechnung_' . $order->get_order_number() . '.pdf"');
        header('Content-Length: ' . filesize($pdf_path));
        readfile($pdf_path);
        exit;
    }
    
    private function get_download_url($order_id) {
        return add_query_arg(array(
            'wlc_download_invoice' => '1',
            'order_id' => $order_id,
            'nonce' => wp_create_nonce('wlc_download_' . $order_id)
        ), home_url());
    }
}
