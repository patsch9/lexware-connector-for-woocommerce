<?php
// ... wie bisher bis zur Funktion wlc_ajax_manual_create_invoice() ...

add_action('wp_ajax_wlc_manual_create_invoice', 'wlc_ajax_manual_create_invoice');
function wlc_ajax_manual_create_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Keine Berechtigung', 'woo-lexware-connector')));
    }

    $order_id = intval($_POST['order_id']);
    $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(array('message' => __('Bestellung nicht gefunden', 'woo-lexware-connector')));
    }

    // NEUE LOGIK: Vor Reset der Lexware-Metas
    $order->delete_meta_data('_wlc_lexware_invoice_id');
    $order->delete_meta_data('_wlc_lexware_invoice_voided');
    $order->delete_meta_data('_wlc_lexware_invoice_number');
    $order->delete_meta_data('_wlc_lexware_credit_note_id');
    $order->save();

    // Füge zur Queue hinzu und verarbeite sofort
    WLC_Queue_Handler::add_to_queue($order_id, 'create_invoice');
    $result = WLC_Queue_Handler::process_next_item();

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('message' => __('Rechnung wurde erstellt', 'woo-lexware-connector')));
}

// ... Rest des Codes bleibt wie gehabt ...
