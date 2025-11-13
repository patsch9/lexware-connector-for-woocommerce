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
        // ACHTUNG: Automatisches Update deaktiviert
        // add_action('woocommerce_saved_order_items', array($this, 'handle_order_items_changed'), 10, 2);
    }
    
    private function register_admin_hooks() {
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_action('save_post_shop_order', array($this, 'save_manual_invoice_data'), 10, 1);
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_manual_invoice_data'), 10, 1);
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
            $order->add_order_note(esc_html__('Lexware Rechnung existiert bereits', 'lexware-connector-for-woocommerce'));
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
    
    // keine automatische Aktualisierung mehr
    // public function handle_order_items_changed($order_id, $items) {...}
    
    public function add_order_metabox() {
        add_meta_box(
            'wlc_lexware_info', 
            esc_html__('Lexware Rechnung', 'lexware-connector-for-woocommerce'), 
            array($this, 'render_order_metabox'), 
            'shop_order', 
            'side', 
            'default'
        );
        add_meta_box(
            'wlc_lexware_info', 
            esc_html__('Lexware Rechnung', 'lexware-connector-for-woocommerce'), 
            array($this, 'render_order_metabox'), 
            'woocommerce_page_wc-orders', 
            'side', 
            'default'
        );
    }
    
    /**
     * Speichert manuell eingegebene Rechnungsdaten
     */
    public function save_manual_invoice_data($order_id) {
        // Sicherheitsprüfung
        if (!isset($_POST['wlc_manual_invoice_nonce']) || 
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wlc_manual_invoice_nonce'])), 'wlc_save_manual_invoice_' . $order_id)) {
            return;
        }
        
        // Berechtigungsprüfung
        if (!current_user_can('edit_shop_orders')) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Prüfe ob bereits eine Rechnung existiert
        $existing_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        if ($existing_invoice_id) {
            return; // Keine Änderung wenn bereits eine Rechnung verknüpft ist
        }
        
        // Hole die manuellen Eingaben
        $manual_invoice_id = isset($_POST['wlc_manual_invoice_id']) ? 
            sanitize_text_field(wp_unslash($_POST['wlc_manual_invoice_id'])) : '';
        $manual_invoice_number = isset($_POST['wlc_manual_invoice_number']) ? 
            sanitize_text_field(wp_unslash($_POST['wlc_manual_invoice_number'])) : '';
        
        // Speichere nur wenn beide Felder ausgefüllt sind
        if (!empty($manual_invoice_id) && !empty($manual_invoice_number)) {
            $order->update_meta_data('_wlc_lexware_invoice_id', $manual_invoice_id);
            $order->update_meta_data('_wlc_lexware_invoice_number', $manual_invoice_number);
            $order->update_meta_data('_wlc_manual_invoice_entry', 'yes');
            $order->save();
            
            $order->add_order_note(
                sprintf(
                    esc_html__('Rechnungsdaten manuell hinterlegt: ID %s, Nummer %s', 'lexware-connector-for-woocommerce'),
                    $manual_invoice_id,
                    $manual_invoice_number
                )
            );
        }
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
        $manual_entry = $order->get_meta('_wlc_manual_invoice_entry');
        
        wp_nonce_field('wlc_save_manual_invoice_' . $order_id, 'wlc_manual_invoice_nonce');
        ?>
        <div class="wlc-metabox">
            <?php if ($lexware_invoice_id): ?>
                <p><strong><?php esc_html_e('Rechnungsnummer:', 'lexware-connector-for-woocommerce'); ?></strong><br><?php echo esc_html($lexware_invoice_number ?: '–'); ?></p>
                <p><strong><?php esc_html_e('Lexware ID:', 'lexware-connector-for-woocommerce'); ?></strong><br><code><?php echo esc_html($lexware_invoice_id); ?></code></p>
                <?php if ($manual_entry === 'yes'): ?>
                    <p><em style="font-size: 11px; color: #666;"><?php esc_html_e('(Manuell hinterlegt)', 'lexware-connector-for-woocommerce'); ?></em></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e('Status:', 'lexware-connector-for-woocommerce'); ?></strong><br><?php 
                if ($invoice_voided === 'yes') { 
                    echo '<span style="color: red;">' . esc_html__('Storniert', 'lexware-connector-for-woocommerce') . '</span>'; 
                } else { 
                    echo '<span style="color: green;">' . esc_html__('Aktiv', 'lexware-connector-for-woocommerce') . '</span>'; 
                } 
                ?></p>
                <?php if ($lexware_credit_note_id): ?>
                    <p><strong><?php esc_html_e('Gutschrift ID:', 'lexware-connector-for-woocommerce'); ?></strong><br><code><?php echo esc_html($lexware_credit_note_id); ?></code></p>
                <?php endif; ?>
                
                <p><a href="https://app.lexoffice.de/voucher/#/<?php echo esc_attr($lexware_invoice_id); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('In Lexware öffnen', 'lexware-connector-for-woocommerce'); ?></a></p>
                <p><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=wlc_download_invoice_pdf&order_id=' . $order_id), 'wlc_download_pdf_' . $order_id)); ?>" class="button button-primary" target="_blank"><?php esc_html_e('📄 Rechnung herunterladen (PDF)', 'lexware-connector-for-woocommerce'); ?></a></p>
                
                <?php if ($invoice_voided !== 'yes'): ?>
                    <p><button type="button" class="button button-secondary wlc-void-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('Rechnung stornieren', 'lexware-connector-for-woocommerce'); ?></button></p>
                    <p><button type="button" class="button button-secondary wlc-send-invoice-email" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('📧 Rechnung per E-Mail senden', 'lexware-connector-for-woocommerce'); ?></button></p>
                    <p><button type="button" class="button button-secondary wlc-update-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('🔄 Rechnung aktualisieren', 'lexware-connector-for-woocommerce'); ?></button></p>
                <?php endif; ?>
                
                <p><button type="button" class="button button-secondary wlc-unlink-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('🔗 Verknüpfung löschen', 'lexware-connector-for-woocommerce'); ?></button></p>
                <p style="font-size: 11px; color: #666;">
                    <?php esc_html_e('Löscht nur die Verknüpfung zur Rechnung, nicht die Rechnung selbst in Lexware.', 'lexware-connector-for-woocommerce'); ?>
                </p>
                
            <?php else: ?>
                <p><?php esc_html_e('Noch keine Rechnung erstellt.', 'lexware-connector-for-woocommerce'); ?></p>
                
                <div class="wlc-manual-invoice-fields" style="margin: 15px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                    <p style="margin-top: 0; font-weight: bold;"><?php esc_html_e('Manuelle Eingabe:', 'lexware-connector-for-woocommerce'); ?></p>
                    <p style="margin-bottom: 8px;">
                        <label for="wlc_manual_invoice_id" style="display: block; margin-bottom: 4px; font-size: 12px;">
                            <?php esc_html_e('Lexware Rechnungs-ID:', 'lexware-connector-for-woocommerce'); ?>
                        </label>
                        <input type="text" 
                               id="wlc_manual_invoice_id" 
                               name="wlc_manual_invoice_id" 
                               class="widefat" 
                               placeholder="z.B. 12345678-1234-1234-1234-123456789012"
                               style="font-size: 12px;">
                    </p>
                    <p style="margin-bottom: 0;">
                        <label for="wlc_manual_invoice_number" style="display: block; margin-bottom: 4px; font-size: 12px;">
                            <?php esc_html_e('Rechnungsnummer:', 'lexware-connector-for-woocommerce'); ?>
                        </label>
                        <input type="text" 
                               id="wlc_manual_invoice_number" 
                               name="wlc_manual_invoice_number" 
                               class="widefat" 
                               placeholder="z.B. RE-2024-001"
                               style="font-size: 12px;">
                    </p>
                    <p style="font-size: 11px; color: #666; margin-bottom: 0;">
                        <?php esc_html_e('Beide Felder ausfüllen und Bestellung speichern, um eine manuelle Verknüpfung zu erstellen.', 'lexware-connector-for-woocommerce'); ?>
                    </p>
                </div>
                
                <p style="text-align: center; margin: 10px 0; color: #666; font-size: 11px;">
                    <?php esc_html_e('— oder —', 'lexware-connector-for-woocommerce'); ?>
                </p>
                
                <p><button type="button" class="button button-primary wlc-create-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('✨ Rechnung jetzt erstellen', 'lexware-connector-for-woocommerce'); ?></button></p>
            <?php endif; ?>
        </div>
        
        <style>
            .wlc-metabox p { margin: 10px 0; }
            .wlc-metabox code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 11px; word-break: break-all;}
            .wlc-metabox .button { width: 100%; text-align: center; box-sizing: border-box;}
            .wlc-manual-invoice-fields input.widefat { width: 100%; box-sizing: border-box; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            function rateLimited(action) {
                return true;
            }
            $('.wlc-create-invoice').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                if (!confirm('<?php esc_attr_e('Rechnung jetzt für diese Bestellung erstellen?', 'lexware-connector-for-woocommerce'); ?>')) { 
                    return; 
                }
                if (!rateLimited('create_invoice')) { return; }
                button.prop('disabled', true).text('<?php esc_attr_e('Wird erstellt...', 'lexware-connector-for-woocommerce'); ?>');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_manual_create_invoice',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim Erstellen der Rechnung', 'lexware-connector-for-woocommerce'); ?>');
                            button.prop('disabled', false).text('<?php esc_attr_e('✨ Rechnung jetzt erstellen', 'lexware-connector-for-woocommerce'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim Erstellen der Rechnung', 'lexware-connector-for-woocommerce'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('✨ Rechnung jetzt erstellen', 'lexware-connector-for-woocommerce'); ?>');
                    }
                });
            });
            
            $('.wlc-void-invoice').on('click', function() {
                if (!confirm('<?php esc_attr_e('Rechnung wirklich stornieren?', 'lexware-connector-for-woocommerce'); ?>')) {
                    return;
                }
                var orderId = $(this).data('order-id');
                var button = $(this);
                if (!rateLimited('void_invoice')) { return; }
                button.prop('disabled', true).text('<?php esc_attr_e('Wird storniert...', 'lexware-connector-for-woocommerce'); ?>');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_manual_void_invoice',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim Stornieren der Rechnung', 'lexware-connector-for-woocommerce'); ?>');
                            button.prop('disabled', false).text('<?php esc_attr_e('Rechnung stornieren', 'lexware-connector-for-woocommerce'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim Stornieren der Rechnung', 'lexware-connector-for-woocommerce'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('Rechnung stornieren', 'lexware-connector-for-woocommerce'); ?>');
                    }
                });
            });
            
            $('.wlc-send-invoice-email').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                
                if (!confirm('<?php esc_attr_e('Rechnung jetzt per E-Mail an den Kunden senden?', 'lexware-connector-for-woocommerce'); ?>')) {
                    return;
                }
                if (!rateLimited('send_invoice_email')) { return; }
                
                button.prop('disabled', true).text('<?php esc_attr_e('Wird gesendet...', 'lexware-connector-for-woocommerce'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_send_invoice_email',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php esc_attr_e('E-Mail wurde versendet', 'lexware-connector-for-woocommerce'); ?>');
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim E-Mail-Versand', 'lexware-connector-for-woocommerce'); ?>');
                        }
                        button.prop('disabled', false).text('<?php esc_attr_e('📧 Rechnung per E-Mail senden', 'lexware-connector-for-woocommerce'); ?>');
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim E-Mail-Versand', 'lexware-connector-for-woocommerce'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('📧 Rechnung per E-Mail senden', 'lexware-connector-for-woocommerce'); ?>');
                    }
                });
            });
            
            $('.wlc-update-invoice').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                if (!confirm('<?php esc_attr_e('Rechnung aktualisieren? Das ist nur möglich, solange die Rechnung noch nicht in Lexware finalisiert ist.', 'lexware-connector-for-woocommerce'); ?>')) {
                    return;
                }
                if (!rateLimited('update_invoice')) { return; }
                button.prop('disabled', true).text('<?php esc_attr_e('Wird aktualisiert...', 'lexware-connector-for-woocommerce'); ?>');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_manual_update_invoice',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim Aktualisieren der Rechnung', 'lexware-connector-for-woocommerce'); ?>');
                            button.prop('disabled', false).text('<?php esc_attr_e('🔄 Rechnung aktualisieren', 'lexware-connector-for-woocommerce'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim Aktualisieren der Rechnung', 'lexware-connector-for-woocommerce'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('🔄 Rechnung aktualisieren', 'lexware-connector-for-woocommerce'); ?>');
                    }
                });
            });

            $('.wlc-unlink-invoice').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                
                if (!confirm('<?php esc_attr_e('Verknüpfung zur Lexware-Rechnung wirklich löschen?\n\nDie Rechnung in Lexware bleibt bestehen, aber du kannst eine neue Rechnung für diese Bestellung erstellen.', 'lexware-connector-for-woocommerce'); ?>')) {
                    return;
                }
                if (!rateLimited('unlink_invoice')) { return; }
                
                button.prop('disabled', true).text('<?php esc_attr_e('Wird gelöscht...', 'lexware-connector-for-woocommerce'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_unlink_invoice',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim Löschen der Verknüpfung', 'lexware-connector-for-woocommerce'); ?>');
                            button.prop('disabled', false).text('<?php esc_attr_e('🔗 Verknüpfung löschen', 'lexware-connector-for-woocommerce'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim Löschen der Verknüpfung', 'lexware-connector-for-woocommerce'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('🔗 Verknüpfung löschen', 'lexware-connector-for-woocommerce'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function add_bulk_action($actions) {
        $actions['wlc_create_invoices'] = esc_html__('Lexware Rechnungen erstellen', 'lexware-connector-for-woocommerce');
        return $actions;
    }
    
    public function handle_bulk_action($redirect_to, $action, $post_ids) {
        if ($action !== 'wlc_create_invoices') {
            return $redirect_to;
        }
        $created = 0;
        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            if (!$order) { continue; }
            if ($order->get_meta('_wlc_lexware_invoice_id')) { continue; }
            WLC_Queue_Handler::add_to_queue($post_id, 'create_invoice');
            $created++;
        }
        $redirect_to = add_query_arg('wlc_invoices_created', $created, $redirect_to);
        return $redirect_to;
    }
    
    public function add_order_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_total') {
                $new_columns['wlc_lexware'] = esc_html__('Lexware', 'lexware-connector-for-woocommerce');
            }
        }
        return $new_columns;
    }
    
    public function render_order_column($column, $post_id) {
        if ($column !== 'wlc_lexware') { return; }
        $order = wc_get_order($post_id);
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        
        if ($lexware_invoice_id) {
            if ($invoice_voided === 'yes') {
                echo '<span style="color: red;">✗ ' . esc_html__('Storniert', 'lexware-connector-for-woocommerce') . '</span>';
            } else {
                echo '<span style="color: green;">✓ ' . esc_html__('Erstellt', 'lexware-connector-for-woocommerce') . '</span>';
            }
        } else {
            echo '<span style="color: gray;">– ' . esc_html__('Keine', 'lexware-connector-for-woocommerce') . '</span>';
        }
    }
}

add_action('wp_ajax_wlc_manual_create_invoice', 'wlc_ajax_manual_create_invoice');
function wlc_ajax_manual_create_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'lexware-connector-for-woocommerce')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_create_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'lexware-connector-for-woocommerce')));
    }
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce')));
    }
    
    $order->delete_meta_data('_wlc_lexware_invoice_id');
    $order->delete_meta_data('_wlc_lexware_invoice_voided');
    $order->delete_meta_data('_wlc_lexware_invoice_number');
    $order->delete_meta_data('_wlc_lexware_credit_note_id');
    $order->save();
    
    WLC_Queue_Handler::add_to_queue($order_id, 'create_invoice');
    $result = WLC_Queue_Handler::process_next_item();
    
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    
    wp_send_json_success(array('message' => esc_html__('Rechnung wurde erstellt', 'lexware-connector-for-woocommerce')));
}

add_action('wp_ajax_wlc_manual_void_invoice', 'wlc_ajax_manual_void_invoice');
function wlc_ajax_manual_void_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'lexware-connector-for-woocommerce')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_void_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'lexware-connector-for-woocommerce')));
    }
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce')));
    }
    
    WLC_Queue_Handler::add_to_queue($order_id, 'void_invoice');
    $result = WLC_Queue_Handler::process_next_item();
    
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    
    wp_send_json_success(array('message' => esc_html__('Rechnung wurde storniert', 'lexware-connector-for-woocommerce')));
}

add_action('wp_ajax_wlc_send_invoice_email', 'wlc_ajax_send_invoice_email');
function wlc_ajax_send_invoice_email() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'lexware-connector-for-woocommerce')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_send_invoice_email', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'lexware-connector-for-woocommerce')));
    }
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce')));
    }
    
    $invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
    if (!$invoice_id) {
        wp_send_json_error(array('message' => esc_html__('Keine Rechnung vorhanden', 'lexware-connector-for-woocommerce')));
    }
    
    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();
    
    if (isset($emails['WLC_Invoice_Email'])) {
        $emails['WLC_Invoice_Email']->trigger($order_id, $order);
        $order->add_order_note(esc_html__('Rechnung per E-Mail versendet', 'lexware-connector-for-woocommerce'));
        wp_send_json_success(array('message' => esc_html__('E-Mail wurde versendet', 'lexware-connector-for-woocommerce')));
    } else {
        wp_send_json_error(array('message' => esc_html__('E-Mail-Klasse nicht gefunden', 'lexware-connector-for-woocommerce')));
    }
}

add_action('wp_ajax_wlc_manual_update_invoice', 'wlc_ajax_manual_update_invoice');
function wlc_ajax_manual_update_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'lexware-connector-for-woocommerce')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_update_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'lexware-connector-for-woocommerce')));
    }
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce')));
    }
    $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
    $already_voided = $order->get_meta('_wlc_lexware_invoice_voided');
    if (!$lexware_invoice_id || $already_voided === 'yes') {
        wp_send_json_error(array('message' => esc_html__('Keine gültige Rechnung vorhanden', 'lexware-connector-for-woocommerce')));
    }
    WLC_Queue_Handler::add_to_queue($order_id, 'update_invoice');
    $result = WLC_Queue_Handler::process_next_item();
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success(array('message' => esc_html__('Rechnung wurde aktualisiert', 'lexware-connector-for-woocommerce')));
}

add_action('wp_ajax_wlc_unlink_invoice', 'wlc_ajax_unlink_invoice');
function wlc_ajax_unlink_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'lexware-connector-for-woocommerce')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_unlink_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'lexware-connector-for-woocommerce')));
    }
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce')));
    }
    
    $order->delete_meta_data('_wlc_lexware_invoice_id');
    $order->delete_meta_data('_wlc_lexware_invoice_number');
    $order->delete_meta_data('_wlc_lexware_credit_note_id');
    $order->delete_meta_data('_wlc_lexware_invoice_voided');
    $order->delete_meta_data('_wlc_lexware_contact_id');
    $order->delete_meta_data('_wlc_manual_invoice_entry');
    $order->save();
    
    $order->add_order_note(esc_html__('Verknüpfung zur Lexware-Rechnung wurde entfernt.', 'lexware-connector-for-woocommerce'));
    
    wp_send_json_success(array('message' => esc_html__('Verknüpfung wurde gelöscht', 'lexware-connector-for-woocommerce')));
}

add_action('wp_ajax_wlc_download_invoice_pdf', 'wlc_ajax_download_invoice_pdf');
function wlc_ajax_download_invoice_pdf() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Keine Berechtigung', 'lexware-connector-for-woocommerce'));
    }
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'wlc_download_pdf_' . $order_id)) {
        wp_die(esc_html__('Ungültiger Sicherheitsschlüssel', 'lexware-connector-for-woocommerce'));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('admin_download_invoice', get_current_user_id(), 20, 60)) {
        wp_die(esc_html__('Zu viele Anfragen. Bitte warten.', 'lexware-connector-for-woocommerce'));
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die(esc_html__('Bestellung nicht gefunden', 'lexware-connector-for-woocommerce'));
    }
    $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
    if (!$lexware_invoice_id) {
        wp_die(esc_html__('Keine Rechnung vorhanden', 'lexware-connector-for-woocommerce'));
    }
    $api_client = new WLC_Lexware_API_Client();
    $pdf_path = $api_client->download_invoice_pdf($lexware_invoice_id);
    if (is_wp_error($pdf_path)) {
        wp_die(esc_html($pdf_path->get_error_message()));
    }
    $upload_dir = wp_upload_dir();
    $pdf_dir = $upload_dir['basedir'] . '/lexware-invoices';
    if (class_exists('WLC_Security')) {
        $safe_path = WLC_Security::sanitize_file_path($pdf_path, $pdf_dir);
        if ($safe_path === false) {
            wp_die(esc_html__('Ungültiger Dateipfad', 'lexware-connector-for-woocommerce'));
        }
        $pdf_path = $safe_path;
    }
    
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
    header('Content-Disposition: inline; filename="rechnung_' . esc_attr($order->get_order_number()) . '.pdf"');
    header('Content-Length: ' . strlen($pdf_content));
    echo $pdf_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF binary data
    exit;
}

add_action('admin_notices', 'wlc_bulk_action_admin_notice');
function wlc_bulk_action_admin_notice() {
    if (!empty($_REQUEST['wlc_invoices_created'])) {
        $count = intval($_REQUEST['wlc_invoices_created']);
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
                _n(
                    '%d Rechnung zur Queue hinzugefügt.',
                    '%d Rechnungen zur Queue hinzugefügt.',
                    $count,
                    'lexware-connector-for-woocommerce'
                ),
                $count
            ))
        );
    }
}
