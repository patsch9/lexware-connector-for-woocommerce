        // Füge Wertgutscheine als eigene Line Items hinzu (mit 0% Steuersatz)
        $voucher_items = $this->get_voucher_items_from_order($order);
        foreach ($voucher_items as $voucher) {
            $line_items[] = array(
                'type' => 'custom',
                'name' => $voucher['name'],
                'description' => 'Einlösung Gutschein',
                'quantity' => 1,
                'unitName' => __('Stück', 'lexware-connector-for-woocommerce'),
                'unitPrice' => array(
                    'currency' => $order->get_currency(),
                    'netAmount' => -$voucher['amount'],  // ← Negativ
                    'grossAmount' => -$voucher['amount'],  // ← Negativ
                    'taxRatePercentage' => 0  // 0% Steuersatz für Wertgutscheine
                )
            );
        }