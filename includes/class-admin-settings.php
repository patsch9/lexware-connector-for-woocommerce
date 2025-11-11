<?php
/**
 * Admin Settings
 * MIT ZAHLUNGSMETHODEN-SPEZIFISCHEN EINSTELLUNGEN UND SICHEREM API-KEY
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_Admin_Settings {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('pre_update_option_wlc_api_key', array($this, 'sanitize_api_key_on_save'), 10, 2);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Lexware Connector', 'woo-lexware-connector'),
            __('Lexware Connector', 'woo-lexware-connector'),
            'manage_woocommerce',
            'wlc-settings',
            array($this, 'render_settings_page')
        );
    }

    public function sanitize_api_key_on_save($new_value, $old_value) {
        $new_value = trim($new_value);
        // Erlaube leeren Wert (API-Key löschen)
        if (empty($new_value)) {
            return '';
        }
        // Kein spezifisches Format prüfen, nur Leerzeichen entfernen und WordPress-sicher machen
        return sanitize_text_field($new_value);
    }

    public function register_settings() {
        register_setting('wlc_api_settings', 'wlc_api_key', array(
            'type' => 'string',
            'default' => ''
        ));
        register_setting('wlc_api_settings', 'wlc_order_statuses');
        register_setting('wlc_api_settings', 'wlc_retry_attempts');
        register_setting('wlc_invoice_settings', 'wlc_invoice_title');
        register_setting('wlc_invoice_settings', 'wlc_invoice_introduction');
        register_setting('wlc_invoice_settings', 'wlc_payment_terms');
        register_setting('wlc_invoice_settings', 'wlc_payment_due_days');
        register_setting('wlc_invoice_settings', 'wlc_closing_text');
        register_setting('wlc_invoice_settings', 'wlc_finalize_immediately');
        $this->register_payment_method_settings();
        register_setting('wlc_sync_settings', 'wlc_auto_sync_contacts');
        register_setting('wlc_sync_settings', 'wlc_show_in_customer_area');
        register_setting('wlc_sync_settings', 'wlc_shipping_as_line_item');
        register_setting('wlc_sync_settings', 'wlc_enable_logging');
        register_setting('wlc_sync_settings', 'wlc_email_on_error');
    }

    private function register_payment_method_settings() {
        if (!function_exists('WC')) {
            return;
        }
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        foreach ($payment_gateways as $gateway) {
            if ($gateway->enabled === 'yes') {
                $gateway_id = $gateway->id;
                register_setting('wlc_invoice_settings', 'wlc_payment_terms_' . $gateway_id);
                register_setting('wlc_invoice_settings', 'wlc_payment_due_days_' . $gateway_id);
            }
        }
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'woocommerce_page_wlc-settings') {
            return;
        }
        wp_enqueue_style('wlc-admin-style', WLC_PLUGIN_URL . 'admin/css/admin-style.css', array(), WLC_VERSION);
        wp_enqueue_script('wlc-admin-script', WLC_PLUGIN_URL . 'admin/js/admin-script.js', array('jquery'), WLC_VERSION, true);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            add_settings_error(
                'wlc_messages',
                'wlc_message',
                __('Einstellungen gespeichert', 'woo-lexware-connector'),
                'updated'
            );
        }
        settings_errors('wlc_messages');
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api';
        ?>
        <div class="wrap wlc-settings-wrap">
            <h1><?php _e('WooCommerce Lexware Connector', 'woo-lexware-connector'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=wlc-settings&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API-Konfiguration', 'woo-lexware-connector'); ?>
                </a>
                <a href="?page=wlc-settings&tab=invoice" class="nav-tab <?php echo $active_tab === 'invoice' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Rechnungseinstellungen', 'woo-lexware-connector'); ?>
                </a>
                <a href="?page=wlc-settings&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Synchronisation', 'woo-lexware-connector'); ?>
                </a>
                <a href="?page=wlc-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logs & Queue', 'woo-lexware-connector'); ?>
                </a>
            </h2>
            <?php if ($active_tab !== 'logs'): ?>
                <form method="post" action="options.php">
                    <?php
                    switch ($active_tab) {
                        case 'api':
                            settings_fields('wlc_api_settings');
                            $this->render_api_tab();
                            break;
                        case 'invoice':
                            settings_fields('wlc_invoice_settings');
                            $this->render_invoice_tab();
                            break;
                        case 'sync':
                            settings_fields('wlc_sync_settings');
                            $this->render_sync_tab();
                            break;
                    }
                    submit_button();
                    ?>
                </form>
            <?php else: ?>
                <?php $this->render_logs_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_api_tab() {
        $api_key = get_option('wlc_api_key', '');
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wlc_api_key"><?php _e('Lexware API Key', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <div style="position: relative; display: inline-block; width: 100%; max-width: 500px;">
                        <input type="text" 
                               id="wlc_api_key" 
                               name="wlc_api_key" 
                               value="<?php echo esc_attr($api_key); ?>" 
                               class="regular-text"
                               placeholder="API-Schlüssel eingeben"
                               autocomplete="off"
                               spellcheck="false"
                               style="font-family: monospace; letter-spacing: 1px; padding-right: 45px; width: 100%;">
                        <button type="button" 
                                class="button button-secondary wlc-toggle-api-key" 
                                style="position: absolute; right: 5px; top: 1px; height: 28px; padding: 0 8px;"
                                title="<?php esc_attr_e('API-Key anzeigen/verbergen', 'woo-lexware-connector'); ?>">
                            <span class="dashicons dashicons-visibility" style="line-height: 28px;"></span>
                        </button>
                    </div>
                    <?php if (!empty($api_key)): ?>
                        <p class="description" style="color: green; margin-top: 8px;">
                            &#10003; <?php _e('API Key gespeichert', 'woo-lexware-connector'); ?>
                            <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px; word-break: break-all;"><?php echo esc_html($api_key); ?></code>
                        </p>
                    <?php else: ?>
                        <p class="description" style="color: #d63638; margin-top: 8px;">
                            &#10007; <?php _e('Kein API Key konfiguriert', 'woo-lexware-connector'); ?>
                        </p>
                    <?php endif; ?>
                    <p class="description" style="margin-top: 8px;">
                        <a href="https://app.lexware.de/settings/#/public-api" target="_blank" rel="noopener">
                            <?php _e('API Key in Lexware erstellen', 'woo-lexware-connector'); ?>
                        </a>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Rechnungen erstellen bei Status', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <?php
                    $selected_statuses = get_option('wlc_order_statuses', array('wc-completed', 'wc-processing'));
                    if (!is_array($selected_statuses)) {
                        $selected_statuses = array();
                    }
                    $order_statuses = wc_get_order_statuses();
                    foreach ($order_statuses as $status => $label) {
                        $checked = in_array($status, $selected_statuses) ? 'checked' : '';
                        ?>
                        <label style="display: block; margin: 5px 0;">
                            <input type="checkbox" name="wlc_order_statuses[]" 
                                   value="<?php echo esc_attr($status); ?>" <?php echo $checked; ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_retry_attempts"><?php _e('Wiederholungsversuche', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <input type="number" id="wlc_retry_attempts" name="wlc_retry_attempts" 
                           value="<?php echo esc_attr(get_option('wlc_retry_attempts', '3')); ?>" 
                           min="0" max="10" class="small-text">
                    <p class="description">
                        <?php _e('Anzahl der automatischen Wiederholungsversuche bei API-Fehlern', 'woo-lexware-connector'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <script>
        jQuery(document).ready(function($) {
            var input = $('#wlc_api_key');
            var button = $('.wlc-toggle-api-key');
            var icon = button.find('.dashicons');
            var isVisible = false;
            if (input.val().length > 0) {
                input.attr('type', 'password');
            }
            button.on('click', function(e) {
                e.preventDefault();
                isVisible = !isVisible;
                if (isVisible) {
                    input.attr('type', 'text');
                    icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    input.attr('type', 'password');
                    icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });
            input.on('focus', function() {
                if (!isVisible) {
                    $(this).attr('type', 'text');
                }
            });
            input.on('blur', function() {
                if (!isVisible && $(this).val().length > 0) {
                    $(this).attr('type', 'password');
                }
            });
        });
        </script>
        <?php
    }

    private function render_invoice_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wlc_invoice_title"><?php _e('Rechnungstitel', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <input type="text" id="wlc_invoice_title" name="wlc_invoice_title" 
                           value="<?php echo esc_attr(get_option('wlc_invoice_title', 'Rechnung')); ?>" 
                           class="regular-text">
                    <p class="description">Shortcodes: [order_number], [order_date], [customer_name]</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_invoice_introduction"><?php _e('Einleitungstext', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_invoice_introduction" name="wlc_invoice_introduction" 
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_invoice_introduction', 'Vielen Dank für Ihre Bestellung [order_number] vom [order_date].')); ?></textarea>
                    <p class="description">Shortcodes: [order_number], [order_date], [customer_name], [customer_company], [total], [payment_method]</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_payment_terms"><?php _e('Standard Zahlungsbedingungen', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_payment_terms" name="wlc_payment_terms" 
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_payment_terms', 'Zahlbar innerhalb von 14 Tagen ohne Abzug.')); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_payment_due_days"><?php _e('Standard Zahlungsziel (Tage)', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <input type="number" id="wlc_payment_due_days" name="wlc_payment_due_days" 
                           value="<?php echo esc_attr(get_option('wlc_payment_due_days', '14')); ?>" 
                           min="0" max="365" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_closing_text"><?php _e('Schlusstext', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_closing_text" name="wlc_closing_text" 
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_closing_text', 'Vielen Dank für Ihr Vertrauen.')); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_finalize_immediately"><?php _e('Rechnungen sofort abschließen', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_finalize_immediately" name="wlc_finalize_immediately" 
                               value="yes" <?php checked(get_option('wlc_finalize_immediately', 'yes'), 'yes'); ?>>
                        <?php _e('Ja, Rechnungen direkt im Status "open" erstellen', 'woo-lexware-connector'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php $this->render_payment_method_settings(); ?>
        <?php
    }

    private function render_payment_method_settings() {
        if (!function_exists('WC')) {
            return;
        }
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        $active_gateways = array_filter($payment_gateways, function($gateway) {
            return $gateway->enabled === 'yes';
        });
        if (empty($active_gateways)) {
            return;
        }
        ?>
        <hr style="margin: 30px 0;">
        <h3>&#128179; <?php _e('Zahlungsmethoden-spezifische Einstellungen', 'woo-lexware-connector'); ?></h3>
        <p class="description">
            <?php _e('Konfiguriere individuelle Zahlungsbedingungen für jede Zahlungsmethode. Leer = Standard-Einstellungen verwenden.', 'woo-lexware-connector'); ?>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 200px;"><?php _e('Zahlungsmethode', 'woo-lexware-connector'); ?></th>
                    <th><?php _e('Zahlungsbedingungen', 'woo-lexware-connector'); ?></th>
                    <th style="width: 120px;"><?php _e('Zahlungsziel (Tage)', 'woo-lexware-connector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_gateways as $gateway): ?>
                    <?php
                    $gateway_id = $gateway->id;
                    $payment_terms = get_option('wlc_payment_terms_' . $gateway_id, '');
                    $payment_days = get_option('wlc_payment_due_days_' . $gateway_id, '');
                    $default_terms = get_option('wlc_payment_terms', '');
                    $default_days = get_option('wlc_payment_due_days', '14');
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($gateway->get_title()); ?></strong><br>
                            <code style="font-size: 11px; color: #666;"><?php echo esc_html($gateway_id); ?></code>
                        </td>
                        <td>
                            <input type="text" 
                                   name="wlc_payment_terms_<?php echo esc_attr($gateway_id); ?>" 
                                   value="<?php echo esc_attr($payment_terms); ?>" 
                                   class="widefat"
                                   placeholder="<?php echo esc_attr($default_terms ?: 'Standard verwenden'); ?>">
                        </td>
                        <td>
                            <input type="number" 
                                   name="wlc_payment_due_days_<?php echo esc_attr($gateway_id); ?>" 
                                   value="<?php echo esc_attr($payment_days); ?>" 
                                   class="small-text"
                                   min="0" max="365"
                                   placeholder="<?php echo esc_attr($default_days); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top: 10px;">
            &#128161; <strong>Beispiele:</strong> PayPal: "Bereits bezahlt per PayPal" + 0 Tage | Rechnung: "Zahlbar innerhalb von 14 Tagen" + 14 Tage
        </p>
        <?php
    }

    private function render_sync_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wlc_auto_sync_contacts"><?php _e('Kontakte automatisch synchronisieren', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_auto_sync_contacts" name="wlc_auto_sync_contacts" 
                               value="yes" <?php checked(get_option('wlc_auto_sync_contacts', 'yes'), 'yes'); ?>>
                        <?php _e('Ja, Kundendaten automatisch in Lexware erstellen/aktualisieren', 'woo-lexware-connector'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_show_in_customer_area"><?php _e('Rechnungen im Kundenbereich anzeigen', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_show_in_customer_area" name="wlc_show_in_customer_area" 
                               value="yes" <?php checked(get_option('wlc_show_in_customer_area', 'yes'), 'yes'); ?>>
                        <?php _e('Ja, Rechnungs-PDFs im "Mein Konto"-Bereich anzeigen', 'woo-lexware-connector'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_shipping_as_line_item"><?php _e('Versandkosten als Position', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_shipping_as_line_item" name="wlc_shipping_as_line_item" 
                               value="yes" <?php checked(get_option('wlc_shipping_as_line_item', 'yes'), 'yes'); ?>>
                        <?php _e('Ja, Versandkosten als separate Rechnungsposition übertragen', 'woo-lexware-connector'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_enable_logging"><?php _e('Logging aktivieren', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_enable_logging" name="wlc_enable_logging" 
                               value="yes" <?php checked(get_option('wlc_enable_logging', 'yes'), 'yes'); ?>>
                        <?php _e('Ja, API-Aufrufe und Fehler protokollieren', 'woo-lexware-connector'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_email_on_error"><?php _e('E-Mail bei Fehlern', 'woo-lexware-connector'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_email_on_error" name="wlc_email_on_error" 
                               value="yes" <?php checked(get_option('wlc_email_on_error', 'yes'), 'yes'); ?>>
                        <?php _e('Ja, Admin per E-Mail über Fehler benachrichtigen', 'woo-lexware-connector'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_logs_tab() {
        $queue_items = WLC_Queue_Handler::get_queue_status();
        $error_logs = get_option('wlc_error_logs', array());
        ?>
        <h2><?php _e('Queue-Status', 'woo-lexware-connector'); ?></h2>
        <p>
            <a href="<?php echo admin_url('admin.php?page=wlc-settings&tab=logs&wlc_process_queue=1'); ?>" 
               class="button button-primary"><?php _e('Queue jetzt verarbeiten', 'woo-lexware-connector'); ?></a>
            <a href="<?php echo admin_url('admin.php?page=wlc-settings&tab=logs&wlc_clear_queue=1'); ?>" 
               class="button button-secondary"
               onclick="return confirm('<?php _e('Queue wirklich leeren?', 'woo-lexware-connector'); ?>');">
                <?php _e('Queue leeren', 'woo-lexware-connector'); ?></a>
        </p>
        <?php
        if (isset($_GET['wlc_process_queue']) && current_user_can('manage_woocommerce')) {
            $result = WLC_Queue_Handler::process_next_item();
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>Fehler: ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . __('Queue-Item erfolgreich verarbeitet!', 'woo-lexware-connector') . '</p></div>';
            }
        }
        if (isset($_GET['wlc_clear_queue']) && current_user_can('manage_woocommerce')) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'wlc_queue';
            $wpdb->query("DELETE FROM $table_name WHERE status = 'failed'");
            echo '<div class="notice notice-success"><p>' . __('Fehlgeschlagene Queue-Items gelöscht!', 'woo-lexware-connector') . '</p></div>';
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Bestellung', 'woo-lexware-connector'); ?></th>
                    <th><?php _e('Aktion', 'woo-lexware-connector'); ?></th>
                    <th><?php _e('Status', 'woo-lexware-connector'); ?></th>
                    <th><?php _e('Versuche', 'woo-lexware-connector'); ?></th>
                    <th><?php _e('Erstellt', 'woo-lexware-connector'); ?></th>
                    <th><?php _e('Fehlermeldung', 'woo-lexware-connector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($queue_items)): ?>
                    <tr><td colspan="6"><?php _e('Queue ist leer', 'woo-lexware-connector'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($queue_items as $item): ?>
                        <tr>
                            <td><a href="<?php echo admin_url('post.php?post=' . $item->order_id . '&action=edit'); ?>">#<?php echo $item->order_id; ?></a></td>
                            <td><?php echo esc_html($item->action); ?></td>
                            <td><?php echo esc_html($item->status); ?></td>
                            <td><?php echo esc_html($item->attempts); ?></td>
                            <td><?php echo esc_html($item->created_at); ?></td>
                            <td><?php echo esc_html($item->error_message ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <h2 style="margin-top: 40px;"><?php _e('Fehler-Log (letzte 20)', 'woo-lexware-connector'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Zeit', 'woo-lexware-connector'); ?></th>
                    <th><?php _e('Titel', 'woo-lexware-connector'); ?></th>
                    <th><?php _e('Nachricht', 'woo-lexware-connector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($error_logs)): ?>
                    <tr><td colspan="3"><?php _e('Keine Fehler', 'woo-lexware-connector'); ?></td></tr>
                <?php else: ?>
                    <?php foreach (array_slice($error_logs, 0, 20) as $error): ?>
                        <tr>
                            <td><?php echo esc_html($error['timestamp']); ?></td>
                            <td><?php echo esc_html($error['title']); ?></td>
                            <td><?php echo esc_html($error['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}