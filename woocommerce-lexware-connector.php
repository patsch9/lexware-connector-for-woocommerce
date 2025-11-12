<?php
/**
 * Plugin Name: Connector Lexware Office for WooCommerce
 * Plugin URI: https://github.com/patsch9/lexware-connector-for-woocommerce
 * Description: Automatische Rechnungserstellung in Lexware Office aus WooCommerce-Bestellungen mit vollständiger Synchronisation und Kundenbereichs-Integration
 * Version: 1.0.0
 * Author: Patrick Schmidt
 * Author URI: https://github.com/patsch9
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lexware-connector-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 * Requires Plugins: woocommerce
 */
/*
 * Hinweis: Dies ist ein inoffizielles Plugin und steht in keiner Verbindung zur Haufe-Lexware GmbH & Co. KG. Lexware® ist eine eingetragene Marke der Haufe-Lexware GmbH & Co. KG.
 */

// Verhindere direkten Zugriff
if (!defined('ABSPATH')) {
    exit;
}

// Plugin-Konstanten definieren
define('WLC_VERSION', '1.0.0');
define('WLC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WLC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WLC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Hauptklasse des Plugins
 */
class WooCommerce_Lexware_Connector {

    /**
     * Singleton-Instanz
     */
    private static $instance = null;

    /**
     * Singleton-Pattern
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        // WooCommerce-Abhängigkeit prüfen
        add_action('plugins_loaded', array($this, 'init'));

        // Aktivierungs-Hook
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Deaktivierungs-Hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // HPOS-Kompatibilität deklarieren
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    /**
     * Deklariere HPOS (High-Performance Order Storage) Kompatibilität
     * Behebt die WooCommerce Inkompatibilitäts-Warnung
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    /**
     * Plugin initialisieren
     */
    public function init() {
        // Prüfe ob WooCommerce aktiv ist
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Hinweis: load_plugin_textdomain() ist ab WP 4.6+ nicht mehr notwendig.
        // WordPress lädt Übersetzungen automatisch basierend auf Text Domain Header.
        // Für manuelle .mo/.po-Dateien im /languages Ordner kann der Aufruf optional bleiben,
        // wird aber von WordPress.org Plugin Check als discouraged markiert.

        // Lade Plugin-Klassen
        $this->load_dependencies();

        // Registriere E-Mail-Klasse in WooCommerce
        add_filter('woocommerce_email_classes', array($this, 'register_invoice_email'));

        // Initialisiere Komponenten
        $this->init_components();
    }

/**
 * Lade alle Abhängigkeiten
 */
private function load_dependencies() {
    require_once WLC_PLUGIN_DIR . 'includes/class-lexware-api-client.php';
    require_once WLC_PLUGIN_DIR . 'includes/class-woo-lexware-integration.php';
    require_once WLC_PLUGIN_DIR . 'includes/class-admin-settings.php';
    require_once WLC_PLUGIN_DIR . 'includes/class-customer-area.php';
    require_once WLC_PLUGIN_DIR . 'includes/class-queue-handler.php';
    // WICHTIG: class-invoice-email.php NICHT hier laden!
}

/**
 * Registriere E-Mail-Klasse in WooCommerce
 */
public function register_invoice_email($email_classes) {
    // Lade E-Mail-Klasse erst hier (lazy loading)
    if (!class_exists('WLC_Invoice_Email')) {
        require_once WLC_PLUGIN_DIR . 'includes/class-invoice-email.php';
    }
    $email_classes['WLC_Invoice_Email'] = new WLC_Invoice_Email();
    return $email_classes;
}

    /**
     * Initialisiere Komponenten
     */
    private function init_components() {
        // Admin-Settings
        if (is_admin()) {
            WLC_Admin_Settings::get_instance();
        }

        // WooCommerce-Integration
        WLC_WooCommerce_Integration::get_instance();

        // Kundenbereich
        WLC_Customer_Area::get_instance();

        // Queue-Handler
        WLC_Queue_Handler::get_instance();
    }

    /**
     * Plugin-Aktivierung
     */
    public function activate() {
        // Prüfe Mindestanforderungen
        if (!$this->check_requirements()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__('WooCommerce Lexware Connector erfordert WooCommerce 6.0 oder höher und PHP 7.4 oder höher.', 'lexware-connector-for-woocommerce'),
                esc_html__('Plugin-Aktivierung fehlgeschlagen', 'lexware-connector-for-woocommerce'),
                array('back_link' => true)
            );
        }

        // Erstelle Datenbank-Tabelle für Queue
        $this->create_database_tables();

        // Erstelle Verzeichnis für PDF-Cache mit verbesserter Sicherheit
        $this->create_secure_upload_directory();

        // Setze Standard-Einstellungen
        $this->set_default_options();

        // Erstelle E-Mail-Templates-Verzeichnis
        $this->create_email_templates_directory();

        // Flush Rewrite Rules
        flush_rewrite_rules();
    }

    /**
     * Prüfe Systemanforderungen
     */
    private function check_requirements() {
        // PHP-Version prüfen
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            return false;
        }

        // WooCommerce-Version prüfen
        if (!class_exists('WooCommerce')) {
            return false;
        }

        if (defined('WC_VERSION') && version_compare(WC_VERSION, '6.0', '<')) {
            return false;
        }

        return true;
    }

    /**
     * Erstelle gesichertes Upload-Verzeichnis
     */
    private function create_secure_upload_directory() {
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/lexware-invoices';

        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        // Erstelle .htaccess für zusätzlichen Schutz
        $htaccess_content = "# WooCommerce Lexware Connector - Geschütztes Verzeichnis\n";
        $htaccess_content .= "Options -Indexes\n";
        $htaccess_content .= "deny from all\n";
        $htaccess_content .= "<FilesMatch \"\\.(pdf)$\">\n";
        $htaccess_content .= "  Order Allow,Deny\n";
        $htaccess_content .= "  Deny from all\n";
        $htaccess_content .= "</FilesMatch>\n";

        file_put_contents($pdf_dir . '/.htaccess', $htaccess_content);

        // Erstelle index.php zum Schutz
        file_put_contents($pdf_dir . '/index.php', '<?php // Silence is golden');
    }

    /**
     * Erstelle E-Mail-Templates-Verzeichnis
     */
    private function create_email_templates_directory() {
        $template_dir = WLC_PLUGIN_DIR . 'templates/emails';
        $plain_dir = $template_dir . '/plain';

        if (!file_exists($template_dir)) {
            wp_mkdir_p($template_dir);
        }

        if (!file_exists($plain_dir)) {
            wp_mkdir_p($plain_dir);
        }
    }

    /**
     * Erstelle Datenbank-Tabellen mit verbesserter Sicherheit
     */
    private function create_database_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'wlc_queue';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            action varchar(50) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            lexware_invoice_id varchar(100) DEFAULT NULL,
            lexware_contact_id varchar(100) DEFAULT NULL,
            attempts int(11) UNSIGNED NOT NULL DEFAULT 0,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Setze Standard-Einstellungen
     */
    private function set_default_options() {
        $defaults = array(
            'wlc_api_key' => '',
            'wlc_order_statuses' => array('wc-completed', 'wc-processing'),
            'wlc_invoice_title' => 'Rechnung',
            'wlc_invoice_introduction' => 'Vielen Dank für Ihre Bestellung [order_number] vom [order_date].',
            'wlc_payment_terms' => 'Zahlbar innerhalb von 14 Tagen ohne Abzug.',
            'wlc_payment_due_days' => '14',
            'wlc_closing_text' => 'Vielen Dank für Ihr Vertrauen.',
            'wlc_finalize_immediately' => 'yes',
            'wlc_auto_sync_contacts' => 'yes',
            'wlc_show_in_customer_area' => 'yes',
            'wlc_enable_logging' => 'yes',
            'wlc_email_on_error' => 'yes',
            'wlc_retry_attempts' => '3',
            'wlc_shipping_as_line_item' => 'yes',
            'wlc_auto_send_email' => 'no'
        );

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Plugin-Deaktivierung
     */
    public function deactivate() {
        // Lösche Cron-Jobs
        wp_clear_scheduled_hook('wlc_process_queue');

        // Flush Rewrite Rules
        flush_rewrite_rules();

        // Hinweis: Daten werden NICHT gelöscht bei Deaktivierung
        // Nur bei Deinstallation (siehe uninstall.php)
    }

    /**
     * WooCommerce-Fehler-Hinweis
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p>
                <strong><?php esc_html_e('WooCommerce Lexware Connector', 'lexware-connector-for-woocommerce'); ?></strong>
                <?php esc_html_e('benötigt WooCommerce 6.0 oder höher. Bitte installieren und aktivieren Sie WooCommerce.', 'lexware-connector-for-woocommerce'); ?>
            </p>
        </div>
        <?php
    }
}

/**
 * Sicherheits-Helper-Funktionen
 */
class WLC_Security {

    /**
     * Validiere und sanitize API-Key
     */
    public static function sanitize_api_key($key) {
        // Entferne Whitespace
        $key = trim($key);

        // Prüfe UUID-Format (Lexware API Keys sind UUIDs)
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $key)) {
            return '';
        }

        return sanitize_text_field($key);
    }

    /**
     * Verhindere Path Traversal bei PDF-Downloads
     */
    public static function sanitize_file_path($path, $allowed_dir) {
        $real_path = realpath($path);
        $allowed_path = realpath($allowed_dir);

        // Prüfe ob Pfad im erlaubten Verzeichnis liegt
        if ($real_path === false || strpos($real_path, $allowed_path) !== 0) {
            return false;
        }

        return $real_path;
    }

    /**
     * Rate Limiting für manuelle Aktionen
     */
    public static function check_rate_limit($action, $user_id, $limit = 10, $period = 60) {
        $transient_key = 'wlc_rate_limit_' . $user_id . '_' . $action;
        $count = get_transient($transient_key);

        if ($count === false) {
            set_transient($transient_key, 1, $period);
            return true;
        }

        if ($count >= $limit) {
            return false;
        }

        set_transient($transient_key, $count + 1, $period);
        return true;
    }
}

// Plugin initialisieren
function woocommerce_lexware_connector() {
    return WooCommerce_Lexware_Connector::get_instance();
}

// Starte Plugin
woocommerce_lexware_connector();

// Security Headers für Admin-Seiten
add_action('admin_init', function() {
    // Nonce-Prüfung nicht nötig für Admin-Page-Parameter (nur Lesezugriff)
    if (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'wlc-settings') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
    }
});
