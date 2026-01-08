        // Füge Wertgutscheine als eigene Line Items hinzu (mit 19% Steuersatz)
        $voucher_items = $this->get_voucher_items_from_order($order);
        foreach ($voucher_items as $voucher) {
            $line_items[] = array(
                'type' => 'custom',
                'name' => $voucher['name'],
                'description' => 'Einlösung Gutschein',
                'quantity' => 1,  // Menge: 1
                'unitName' => __('Stück', 'lexware-connector-for-woocommerce'),
                'unitPrice' => array(
                    'currency' => $order->get_currency(),
                    'netAmount' => -$voucher['amount'] / 1.19,  // Preis: Negativ, netto
                    'grossAmount' => -$voucher['amount'],  // Preis: Negativ, brutto
                    'taxRatePercentage' => 19  // 19% Steuersatz für Kurse
                )
            );
        }