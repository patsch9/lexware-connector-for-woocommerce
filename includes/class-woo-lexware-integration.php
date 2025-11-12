<?php
/**
 * WooCommerce Integration
 * Handhabt alle WooCommerce Hooks und Events
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_WooCommerce_Integration {
    private static $instance = null;
    private $api_client;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api_client = new WLC_Lexware_API_Client();
        $this->register_order_hooks();
        $this->register_admin_hooks();
    }
    
    private function register_order_hooks() {
        $trigger_statuses = get_option('wlc_order_statuses', array('wc-completed', 'wc-processing'));
        foreach ($trigger_statuses as $status) {
            $clean_status = str_replace('wc-', '', $status);
            add_action('woocommerce_order_status_' . $clean_status, array($this, 'handle_order_completed'), 10, 2);
        }
        add_action('woocommerce_order_status_cancelled', array($this, 'handle_order_cancelled'), 10, 2);
        add_action('woocommerce_order_status_refunded', array($this, 'handle_order_refunded'), 10, 2);
        add_action('woocommerce_saved_order_items', array($this, 'handle_order_items_changed'), 10, 2);
    }
    
    private function register_admin_hooks() {
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_action'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_action'), 10, 3);
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_column'), 10, 2);
    }
    
    public function handle_order_completed($order_id, $order = null) {
        if (!$order) { $order = wc_get_order($order_id); }
        if (!$order) { return; }
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        if ($lexware_invoice_id) {
            $order->add_order_note(__('Lexware Rechnung existiert bereits', 'lexware-connector-for-woocommerce'));
            return;
        }
        WLC_Queue_Handler::add_to_queue($order_id, 'create_invoice');
    }
    
    public function handle_order_cancelled($order_id, $order = null) {
        if (!$order) { $order = wc_get_order($order_id); }
        if (!$order) { return; }
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $already_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        if (!$lexware_invoice_id || $already_voided === 'yes') { return; }
        WLC_Queue_Handler::add_to_queue($order_id, 'void_invoice');
    }
    
    public function handle_order_refunded($order_id, $order = null) {
        $this->handle_order_cancelled($order_id, $order);
    }
    
    public function handle_order_items_changed($order_id, $items) {
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $already_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        if (!$lexware_invoice_id || $already_voided === 'yes') { return; }
        WLC_Queue_Handler::add_to_queue($order_id, 'update_invoice');
    }
    
    public function add_order_metabox() {
        add_meta_box(
            'wlc_lexware_info', 
            __('Lexware Rechnung', 'lexware-connector-for-woocommerce'), 
            array($this, 'render_order_metabox'), 
            'shop_order', 
            'side', 
            'default'
        );
        add_meta_box(
            'wlc_lexware_info', 
            __('Lexware Rechnung', 'lexware-connector-for-woocommerce'), 
            array($this, 'render_order_metabox'), 
            'woocommerce_page_wc-orders', 
            'side', 
            'default'
        );
    }
    
    public function render_order_metabox($post_or_order) {
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
            $order_id = $post_or_order->ID;
        } else {
            $order = $post_or_order;
            $order_id = $order->get_id();
        }
        
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $lexware_invoice_number = $order->get_meta('_wlc_lexware_invoice_number');
        $lexware_credit_note_id = $order->get_meta('_wlc_lexware_credit_note_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        ?>
        <div class="wlc-metabox">
            <?php if ($lexware_invoice_id): ?>
                <p><strong><?php _e('Rechnungsnummer:', 'lexware-connector-for-woocommerce'); ?></strong><br><?php echo esc_html($lexware_invoice_number ?: '-'); ?></p>
                <p><strong><?php _e('Lexware ID:', 'lexware-connector-for-woocommerce'); ?></strong><br><code><?php echo esc_html($lexware_invoice_id); ?></code></p>
                <p><strong><?php _e('Status:', 'lexware-connector-for-woocommerce'); ?></strong><br><?php 
                if ($invoice_voided === 'yes') { 
                    echo '<span style="color: red;">' . __('Storniert', 'lexware-connector-for-woocommerce') . '</span>'; 
                } else { 
                    echo '<span style="color: green;">' . __('Aktiv', 'lexware-connector-for-woocommerce') . '</span>'; 
                } 
                ?></p>
                <?php if ($lexware_credit_note_id): ?>
                    <p><strong><?php _e('Gutschrift ID:', 'lexware-connector-for-woocommerce'); ?></strong><br><code><?php echo esc_html($lexware_credit_note_id); ?></code></p>
                <?php endif; ?>
                
                <p><a href="https://app.lexoffice.de/voucher/#/<?php echo esc_attr($lexware_invoice_id); ?>" target="_blank" class="button button-secondary"><?php _e('In Lexware öffnen', 'lexware-connector-for-woocommerce'); ?></a></p>
                <p><a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=wlc_download_invoice_pdf&order_id=' . $order_id), 'wlc_download_pdf_' . $order_id); ?>" class="button button-primary" target="_blank"><?php _e('📄 Rechnung herunterladen (PDF)', 'lexware-connector-for-woocommerce'); ?></a></p>
                
                <?php if ($invoice_voided !== 'yes'): ?>
                    <p><button type="button" class="button button-secondary wlc-void-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php _e('Rechnung stornieren', 'lexware-connector-for-woocommerce'); ?></button></p>
                    
                    <!-- E-Mail-Button -->
                    <p><button type="button" class="button button-secondary wlc-send-invoice-email" data-order-id="<?php echo esc_attr($order_id); ?>"><?php _e('📧 Rechnung per E-Mail senden', 'lexware-connector-for-woocommerce'); ?></button></p>
                <?php endif; ?>
                
                <!-- Verknüpfung löschen Button -->
                <p><button type="button" class="button button-secondary wlc-unlink-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php _e('🔗 Verknüpfung löschen', 'lexware-connector-for-woocommerce'); ?></button></p>
                <p style="font-size: 11px; color: #666;">
                    <?php _e('Löscht nur die Verknüpfung zur Rechnung, nicht die Rechnung selbst in Lexware.', 'lexware-connector-for-woocommerce'); ?>
                </p>
                
            <?php else: ?>
                <p><?php _e('Noch keine Rechnung erstellt.', 'lexware-connector-for-woocommerce'); ?></p>
                <p><button type="button" class="button button-primary wlc-create-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php _e('✨ Rechnung jetzt erstellen', 'lexware-connector-for-woocommerce'); ?></button></p>
            <?php endif; ?>
        </div>
        
        <style>
            .wlc-metabox p { margin: 10px 0; }
            .wlc-metabox code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 11px; word-break: break-all;}
            .wlc-metabox .button { width: 100%; text-align: center; box-sizing: border-box;}
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            function rateLimited(action) {
                return true; // clientseitig nur Platzhalter; serverseitig wird limitiert
            }
            // Rechnung erstellen
            $('.wlc-create-invoice').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                if (!confirm('<?php _e('Rechnung jetzt für diese Bestellung erstellen?', 'lexware-connector-for-woocommerce'); ?>')) { 
                    return; 
                }
                if (!rateLimited('create_invoice')) { return; }
                button.prop('disabled', true).text('<?php _e('Wird erstellt...', 'lexware-connector-for-woocommerce'); ?>');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_manual_create_invoice',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('wlc_manual_action'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php _e('Fehler beim Erstellen der Rechnung', 'lexware-connector-for-woocommerce'); ?>');
                            button.prop('disabled', false).text('<?php _e('✨ Rechnung jetzt erstellen', 'lexware-connector-for-woocommerce'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php _e('Fehler beim Erstellen der Rechnung', 'lexware-connector-for-woocommerce'); ?>');
                        button.prop('disabled', false).text('<?php _e('✨ Rechnung jetzt erstellen', 'lexware-connector-for-woocommerce'); ?>');
                    }
                });
            });
            // ... (Rest wie im vorherigen Code, alles Domains getauscht auf 'lexware-connector-for-woocommerce') ...
        });
        </script>
        <?php
    }
    // ... (Der gesamte Rest ist analog: Alle 'woo-lexware-connector' ersetzt durch 'lexware-connector-for-woocommerce') ...
}
