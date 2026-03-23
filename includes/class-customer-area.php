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
            <h2><?php esc_html_e('Rechnung', 'lexware-connector-for-woocommerce'); ?></h2>
            <p>
                <a href="<?php echo esc_url($download_url); ?>" 
                   class="button" 
                   target="_blank">
                    <?php esc_html_e('Rechnung herunterladen (PDF)', 'lexware-connector-for-woocommerce'); ?>
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
                $new_columns['invoice'] = esc_html__('Rechnung', 'lexware-connector-for-woocommerce');
            }
        }
        
        return $new_columns;
    }
    
    public function render_orders_column($order) {
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        
        if ($lexware_invoice_id && $invoice_voided !== 'yes') {
            $download_url = $this->get_download_url($order->get_id());
            echo '<a href="' . esc_url($download_url) . '" target="_blank">' . esc_html__('PDF', 'lexware-connector-for-woocommerce') . '</a>';
        } else {
            echo '–';
        }
    }
    
    public function handle_invoice_download() {
        if (!isset($_GET['wlc_download_invoice']) || !isset($_GET['order_id']) || !isset($_GET['nonce'])) {
            return;
        }
        
        $order_id = intval($_GET['order_id']);
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        
        if (!wp_verify_nonce($nonce, 'wlc_download_' . $order_id)) {
            wp_die(esc_html__('Ungültiger Sicherheitsschlüssel', 'lexware-connector-for-woocommerce'));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die(esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce'));
        }
        
        // Prüfe ob User berechtigt ist
        if (!current_user_can('view_order', $order_id)) {
            wp_die(esc_html__('Keine Berechtigung', 'lexware-connector-for-woocommerce'));
        }
        
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        
        if (!$lexware_invoice_id) {
            wp_die(esc_html__('Keine Rechnung vorhanden', 'lexware-connector-for-woocommerce'));
        }
        
        // Lade PDF herunter
        $api_client = new WLC_Lexware_API_Client();
        $pdf_path = $api_client->download_invoice_pdf($lexware_invoice_id);
        
        if (is_wp_error($pdf_path)) {
            wp_die(esc_html($pdf_path->get_error_message()));
        }
        
        // Sende PDF mit WP_Filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if (!$wp_filesystem->exists($pdf_path)) {
            wp_die(esc_html__('PDF-Datei nicht gefunden', 'lexware-connector-for-woocommerce'));
        }
        
        $pdf_content = $wp_filesystem->get_contents($pdf_path);
        
        if ($pdf_content === false) {
            wp_die(esc_html__('PDF konnte nicht gelesen werden', 'lexware-connector-for-woocommerce'));
        }
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="rechnung_' . esc_attr($order->get_order_number()) . '.pdf"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF binary data
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
