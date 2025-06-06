<?php 
namespace WC4AGC;

class OrderSync {
    public static function send_to_erp($order_id) {
        $debug = get_option(\WC4AGC\Constants::OPTION_DEBUG_MODE, '0') === '1';
        try {
            // 0) Conectar al ERP vía wpdb
            $wpdbAGC = new \wpdb(
                'intercambiador',
                'Ii6~fq68',
                'edinumen_es_intercambiador',
                '82.223.152.162'
            );
            $wpdbAGC->set_prefix('');

            $order = wc_get_order($order_id);
            $email = $order->get_billing_email();

            // Idempotencia: si ya existe meta '_wc4agc_albaran', no reenvía
            if (get_post_meta($order_id, '_wc4agc_albaran', true)) {
                return;
            }

            // 1) Librado (cliente)
            $order_number = intval(explode('-', get_post_meta($order_id, '_order_number', true))[1]);
            $paid_date = get_post_meta($order_id, '_paid_date', true);

            $librado = $wpdbAGC->get_row(
                $wpdbAGC->prepare(
                    "SELECT * FROM Librados WHERE EMail = %s",
                    $email
                )
            );

            if ($librado && $librado->Numero) {
                $librado_num = $librado->Numero;
                $librado_tipo = $librado->Tipo;
            } else {
                // Crear nuevo librado
                $librado_num = '-' . abs(500000 + $order_number);
                $librado_tipo = 8;
                $rec_equiv = 'E';
                $state_legend = WC()->countries->get_states($order->get_billing_country())[$order->get_billing_state()];

                $data = [
                    'Tipo' => $librado_tipo,
                    'Numero' => $librado_num,
                    'Nombre' => html_entity_decode($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'NombreCont' => 'US6',
                    'Contacto' => '1000',
                    'CIF' => '00000',
                    'Domicilio' => $order->get_billing_address_1(),
                    'DomicilioCont' => substr($order->get_billing_address_1(), 40),
                    'CodPostal' => $order->get_billing_postcode(),
                    'Poblacion' => html_entity_decode($order->get_billing_city()),
                    'Provincia' => $state_legend,
                    'Pais' => 'Estados Unidos',
                    'Telefonos' => $order->get_billing_phone(),
                    'Fax' => 'USA',
                    'Email' => $email,
                    'Codigo' => 'WEB-USA',
                    'FormaPago' => '16',
                    'Tarifa' => '5',
                    'RecEquiv' => $rec_equiv,
                    'Zona' => '15',
                    'XVACIO00' => '1',
                ];
                $wpdbAGC->insert('Librados', $data);

                // Crear usuario asociado al librado
                $password = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 8);
                $wpdbAGC->insert('Usuarios', [
                    'Usuario' => $email,
                    'Contrasena' => $password,
                    'TipoLibrado' => $librado_tipo,
                    'Librado' => $librado_num,
                    'XVACIO00' => '1',
                ]);
            }

            // 2) Cabecera de albarán
            $documento = $wpdbAGC->get_row("SELECT Numero FROM Cabeceras ORDER BY Numero DESC LIMIT 1");
            $num_doc = $documento->Numero + 1;

            $dto = $order->get_total_discount() > 0
                ? round(($order->get_total_discount() * 100) / $order->get_subtotal(), 2)
                : 0;

            $wpdbAGC->insert('Cabeceras', [
                'Tipo' => '0',
                'Serie' => '0',
                'Numero' => $num_doc,
                'Referencia' => 'USAEDI' . $order_number,
                'TipoLibrado' => $librado_tipo,
                'librado' => $librado_num,
                'Expedicion' => $paid_date,
                'FormaPago' => '16',
                'Dto1' => $dto,
                'GastosEnvio' => $order->get_shipping_total() * 0.95,
                'XVACIO00' => '1',
            ]);

            // 3) Líneas de albarán
            $i = 1;
            foreach ($order->get_items() as $item_id => $item) {
                $variation = $item->get_variation_id();
                $sku = get_post_meta($variation, '_sku', true);
                $sale = floatval(get_post_meta($variation, '_sale_price', true));
                $regular = floatval(get_post_meta($variation, '_regular_price', true));

                $dto_line = ($sale < $regular && $sale > 0)
                    ? round(100 - (($sale * 100) / $regular), 2)
                    : 0;

                $ref = $wpdbAGC->get_var(
                    $wpdbAGC->prepare("SELECT Ref1 FROM Products WHERE ref2 = %s", $sku)
                );

                $wpdbAGC->insert('Lineas', [
                    'Tipo' => '0',
                    'Serie' => '0',
                    'Numero' => $num_doc,
                    'Linea' => $i++,
                    'Referencia' => $ref,
                    'Almacen' => '108',
                    'Cantidad' => $item->get_quantity(),
                    'Precio' => $regular * 0.95,
                    'DTO1' => $dto_line,
                    'Descripcion' => $item->get_name(),
                    'XVACIO00' => '1',
                ]);
            }

            // 4) Marcar como sincronizado y cambiar estado
            update_post_meta($order_id, '_wc4agc_albaran', $num_doc);
            $order->update_status('agc-transfer');
        } catch (\Exception $e) {
            $msg = 'Error al sincronizar pedido: ' . $e->getMessage();
            if ($debug) {
                $msg .= ' | Order ID: ' . $order_id;
                // Puedes añadir aquí más detalles relevantes, como los datos del pedido o la consulta SQL si aplica
            }
            \WC4AGC\Logger::instance()->log('orders', $msg, 'error');
            throw $e;
        }
    }
} 