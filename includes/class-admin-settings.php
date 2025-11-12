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
            esc_html__('Lexware Connector', 'lexware-connector-for-woocommerce'),
            esc_html__('Lexware Connector', 'lexware-connector-for-woocommerce'),
            'manage_woocommerce',
            'wlc-settings',
            array($this, 'render_settings_page')
        );
    }

    public function sanitize_api_key_on_save($new_value, $old_value) {
        // Wenn Feld leer bleibt, alten Wert beibehalten (nicht klartext anzeigen)
        if ($new_value === null || trim((string)$new_value) === '') {
            return (string)$old_value;
        }
        return sanitize_text_field(trim((string)$new_value));
    }

    public function register_settings() {
        register_setting('wlc_api_settings', 'wlc_api_key', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_api_settings', 'wlc_order_statuses', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_order_statuses')
        ));
        register_setting('wlc_api_settings', 'wlc_retry_attempts', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint'
        ));
        register_setting('wlc_invoice_settings', 'wlc_invoice_title', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_invoice_introduction', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_payment_terms', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_payment_due_days', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_closing_text', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_finalize_immediately', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        $this->register_payment_method_settings();
        register_setting('wlc_sync_settings', 'wlc_auto_sync_contacts', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_sync_settings', 'wlc_show_in_customer_area', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_sync_settings', 'wlc_shipping_as_line_item', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_sync_settings', 'wlc_enable_logging', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_sync_settings', 'wlc_email_on_error', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_sync_settings', 'wlc_auto_send_email', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
    }

    public function sanitize_order_statuses($value) {
        if (!is_array($value)) {
            return array();
        }
        return array_map('sanitize_text_field', $value);
    }

    private function register_payment_method_settings() {
        if (!function_exists('WC')) {
            return;
        }
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        foreach ($payment_gateways as $gateway) {
            if ($gateway->enabled === 'yes') {
                $gateway_id = $gateway->id;
                register_setting('wlc_invoice_settings', 'wlc_payment_terms_' . $gateway_id, array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ));
                register_setting('wlc_invoice_settings', 'wlc_payment_due_days_' . $gateway_id, array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ));
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
        // Nonce wird von WordPress Settings API automatisch geprüft
        if (isset($_GET['settings-updated']) && sanitize_text_field(wp_unslash($_GET['settings-updated'])) === 'true') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_settings_error(
                'wlc_messages',
                'wlc_message',
                esc_html__('Einstellungen gespeichert', 'lexware-connector-for-woocommerce'),
                'updated'
            );
        }
        settings_errors('wlc_messages');
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'api'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap wlc-settings-wrap">
            <h1><?php esc_html_e('WooCommerce Lexware Connector', 'lexware-connector-for-woocommerce'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=wlc-settings&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('API-Konfiguration', 'lexware-connector-for-woocommerce'); ?>
                </a>
                <a href="?page=wlc-settings&tab=invoice" class="nav-tab <?php echo $active_tab === 'invoice' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Rechnungseinstellungen', 'lexware-connector-for-woocommerce'); ?>
                </a>
                <a href="?page=wlc-settings&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Synchronisation', 'lexware-connector-for-woocommerce'); ?>
                </a>
                <a href="?page=wlc-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs & Queue', 'lexware-connector-for-woocommerce'); ?>
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
                    <label for="wlc_api_key"><?php esc_html_e('Lexware API Key', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <div style="position: relative; display: inline-block; width: 100%; max-width: 500px;">
                        <input type="password"
                               id="wlc_api_key"
                               name="wlc_api_key"
                               value=""
                               class="regular-text"
                               placeholder="<?php echo esc_attr($api_key ? esc_html__('Gespeichert – leer lassen, um nicht zu ändern', 'lexware-connector-for-woocommerce') : esc_html__('API-Schlüssel eingeben', 'lexware-connector-for-woocommerce')); ?>"
                               autocomplete="off"
                               spellcheck="false"
                               style="font-family: monospace; letter-spacing: 1px; padding-right: 45px; width: 100%;">
                        <button type="button"
                                class="button button-secondary wlc-toggle-api-key"
                                style="position: absolute; right: 5px; top: 1px; height: 28px; padding: 0 8px;"
                                title="<?php esc_attr_e('API-Key anzeigen/verbergen', 'lexware-connector-for-woocommerce'); ?>">
                            <span class="dashicons dashicons-visibility" style="line-height: 28px;"></span>
                        </button>
                    </div>
                    <p class="description" style="margin-top: 8px;">
                        <?php echo esc_html($api_key ? esc_html__('Ein API-Key ist gespeichert. Gib einen neuen ein, um ihn zu ersetzen – leer lassen, um nichts zu ändern.', 'lexware-connector-for-woocommerce') : esc_html__('Bitte API-Key speichern.', 'lexware-connector-for-woocommerce')); ?>
                    </p>
                    <p class="description" style="margin-top: 8px;">
                        <a href="https://app.lexware.de/settings/#/public-api" target="_blank" rel="noopener">
                            <?php esc_html_e('API Key in Lexware erstellen', 'lexware-connector-for-woocommerce'); ?>
                        </a>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Rechnungen erstellen bei Status', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <?php
                    $selected_statuses = get_option('wlc_order_statuses', array('wc-completed', 'wc-processing'));
                    if (!is_array($selected_statuses)) {
                        $selected_statuses = array();
                    }
                    $order_statuses = wc_get_order_statuses();
                    foreach ($order_statuses as $status => $label) {
                        $checked = in_array($status, $selected_statuses, true) ? 'checked' : '';
                        ?>
                        <label style="display: block; margin: 5px 0;">
                            <input type="checkbox" name="wlc_order_statuses[]"
                                   value="<?php echo esc_attr($status); ?>" <?php echo esc_attr($checked); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_retry_attempts"><?php esc_html_e('Wiederholungsversuche', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <input type="number" id="wlc_retry_attempts" name="wlc_retry_attempts"
                           value="<?php echo esc_attr(get_option('wlc_retry_attempts', '3')); ?>"
                           min="0" max="10" class="small-text">
                    <p class="description">
                        <?php esc_html_e('Anzahl der automatischen Wiederholungsversuche bei API-Fehlern', 'lexware-connector-for-woocommerce'); ?>
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
        });
        </script>
        <?php
    }

    private function render_invoice_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wlc_invoice_title"><?php esc_html_e('Rechnungstitel', 'lexware-connector-for-woocommerce'); ?></label>
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
                    <label for="wlc_invoice_introduction"><?php esc_html_e('Einleitungstext', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_invoice_introduction" name="wlc_invoice_introduction"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_invoice_introduction', 'Vielen Dank für Ihre Bestellung [order_number] vom [order_date].')); ?></textarea>
                    <p class="description">Shortcodes: [order_number], [order_date], [customer_name], [customer_company], [total], [payment_method]</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_payment_terms"><?php esc_html_e('Standard Zahlungsbedingungen', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_payment_terms" name="wlc_payment_terms"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_payment_terms', 'Zahlbar innerhalb von 14 Tagen ohne Abzug.')); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_payment_due_days"><?php esc_html_e('Standard Zahlungsziel (Tage)', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <input type="number" id="wlc_payment_due_days" name="wlc_payment_due_days"
                           value="<?php echo esc_attr(get_option('wlc_payment_due_days', '14')); ?>"
                           min="0" max="365" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_closing_text"><?php esc_html_e('Schlusstext', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_closing_text" name="wlc_closing_text"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_closing_text', 'Vielen Dank für Ihr Vertrauen.')); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_finalize_immediately"><?php esc_html_e('Rechnungen sofort abschließen', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_finalize_immediately" name="wlc_finalize_immediately"
                               value="yes" <?php checked(get_option('wlc_finalize_immediately', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Rechnungen direkt im Status "open" erstellen', 'lexware-connector-for-woocommerce'); ?>
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
        <h3>💳 <?php esc_html_e('Zahlungsmethoden-spezifische Einstellungen', 'lexware-connector-for-woocommerce'); ?></h3>
        <p class="description">
            <?php esc_html_e('Konfiguriere individuelle Zahlungsbedingungen für jede Zahlungsmethode. Leer = Standard-Einstellungen verwenden.', 'lexware-connector-for-woocommerce'); ?>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 200px;"><?php esc_html_e('Zahlungsmethode', 'lexware-connector-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Zahlungsbedingungen', 'lexware-connector-for-woocommerce'); ?></th>
                    <th style="width: 120px;"><?php esc_html_e('Zahlungsziel (Tage)', 'lexware-connector-for-woocommerce'); ?></th>
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
                            <code style="font-size: 11px; color: #666;"> <?php echo esc_html($gateway_id); ?></code>
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
            💡 <strong>Beispiele:</strong> PayPal: "Bereits bezahlt per PayPal" + 0 Tage | Rechnung: "Zahlbar innerhalb von 14 Tagen" + 14 Tage
        </p>
        <?php
    }
    
    private function render_sync_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wlc_auto_sync_contacts"><?php esc_html_e('Kontakte automatisch synchronisieren', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_auto_sync_contacts" name="wlc_auto_sync_contacts" 
                               value="yes" <?php checked(get_option('wlc_auto_sync_contacts', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Kundendaten automatisch in Lexware erstellen/aktualisieren', 'lexware-connector-for-woocommerce'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_show_in_customer_area"><?php esc_html_e('Rechnungen im Kundenbereich anzeigen', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_show_in_customer_area" name="wlc_show_in_customer_area" 
                               value="yes" <?php checked(get_option('wlc_show_in_customer_area', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Rechnungs-PDFs im "Mein Konto"-Bereich anzeigen', 'lexware-connector-for-woocommerce'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_shipping_as_line_item"><?php esc_html_e('Versandkosten als Position', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_shipping_as_line_item" name="wlc_shipping_as_line_item" 
                               value="yes" <?php checked(get_option('wlc_shipping_as_line_item', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Versandkosten als separate Rechnungsposition übertragen', 'lexware-connector-for-woocommerce'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_enable_logging"><?php esc_html_e('Logging aktivieren', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_enable_logging" name="wlc_enable_logging" 
                               value="yes" <?php checked(get_option('wlc_enable_logging', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, API-Aufrufe und Fehler protokollieren', 'lexware-connector-for-woocommerce'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_email_on_error"><?php esc_html_e('E-Mail bei Fehlern', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_email_on_error" name="wlc_email_on_error" 
                               value="yes" <?php checked(get_option('wlc_email_on_error', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Admin per E-Mail über Fehler benachrichtigen', 'lexware-connector-for-woocommerce'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_auto_send_email"><?php esc_html_e('Rechnung automatisch per E-Mail versenden', 'lexware-connector-for-woocommerce'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_auto_send_email" name="wlc_auto_send_email" 
                               value="yes" <?php checked(get_option('wlc_auto_send_email', 'no'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Rechnung automatisch nach Erstellung per E-Mail an Kunden senden', 'lexware-connector-for-woocommerce'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Die E-Mail-Vorlage kann unter WooCommerce → Einstellungen → E-Mails angepasst werden.', 'lexware-connector-for-woocommerce'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_logs_tab() {
        $queue_items = WLC_Queue_Handler::get_queue_status();
        $error_logs = get_option('wlc_error_logs', array());
        ?>
        <h2><?php esc_html_e('Queue-Status', 'lexware-connector-for-woocommerce'); ?></h2>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wlc-settings&tab=logs&wlc_process_queue=1')); ?>" 
               class="button button-primary"><?php esc_html_e('Queue jetzt verarbeiten', 'lexware-connector-for-woocommerce'); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wlc-settings&tab=logs&wlc_clear_queue=1')); ?>" 
               class="button button-secondary"
               onclick="return confirm('<?php esc_attr_e('Queue wirklich leeren?', 'lexware-connector-for-woocommerce'); ?>');">
                <?php esc_html_e('Queue leeren', 'lexware-connector-for-woocommerce'); ?></a>
        </p>
        <?php
        // Nonce wird hier nicht benötigt, da nur Lese-Aktion (GET parameter von selbst gesetzt)
        if (isset($_GET['wlc_process_queue']) && current_user_can('manage_woocommerce')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $result = WLC_Queue_Handler::process_next_item();
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Fehler: ', 'lexware-connector-for-woocommerce') . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html__('Queue-Item erfolgreich verarbeitet!', 'lexware-connector-for-woocommerce') . '</p></div>';
            }
        }
        if (isset($_GET['wlc_clear_queue']) && current_user_can('manage_woocommerce')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            global $wpdb;
            $table_name = $wpdb->prefix . 'wlc_queue';
            $wpdb->query($wpdb->prepare("DELETE FROM " . esc_sql($table_name) . " WHERE status = %s", 'failed')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            echo '<div class="notice notice-success"><p>' . esc_html__('Fehlgeschlagene Queue-Items gelöscht!', 'lexware-connector-for-woocommerce') . '</p></div>';
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Bestellung', 'lexware-connector-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Aktion', 'lexware-connector-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Status', 'lexware-connector-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Versuche', 'lexware-connector-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Erstellt', 'lexware-connector-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Fehlermeldung', 'lexware-connector-for-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($queue_items)): ?>
                    <tr><td colspan="6"><?php esc_html_e('Queue ist leer', 'lexware-connector-for-woocommerce'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($queue_items as $item): ?>
                        <tr>
                            <td><a href="<?php echo esc_url(admin_url('post.php?post=' . absint($item->order_id) . '&action=edit')); ?>">#<?php echo esc_html($item->order_id); ?></a></td>
                            <td><?php echo esc_html($item->action); ?></td>
                            <td><?php echo esc_html($item->status); ?></td>
                            <td><?php echo esc_html($item->attempts); ?></td>
                            <td><?php echo esc_html($item->created_at); ?></td>
                            <td><?php echo esc_html($item->error_message ?: '–'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <h2 style="margin-top: 40px;"> <?php esc_html_e('Fehler-Log (letzte 20)', 'lexware-connector-for-woocommerce'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Zeit', 'lexware-connector-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Titel', 'lexware-connector-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Nachricht', 'lexware-connector-for-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($error_logs)): ?>
                    <tr><td colspan="3"><?php esc_html_e('Keine Fehler', 'lexware-connector-for-woocommerce'); ?></td></tr>
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
