<?php
/**
 * Generador de facturas PDF
 * 
 * Genera facturas en formato PDF profesional y las sube a R2.
 * Las facturas nunca se eliminan (requisito legal).
 * 
 * Utiliza HTML-to-PDF nativo sin dependencias externas (DOMPDF).
 * Si DOMPDF no está disponible, genera un HTML guardado en R2.
 */

require_once __DIR__ . '/../config/R2Service.php';

/**
 * Genera el PDF de una factura y lo sube a R2.
 * 
 * @param PDO $db Conexión a base de datos
 * @param int $facturaId ID de la factura
 * @return array|null ['pdf_path' => string] o null si falla
 */
function generateInvoicePdf(PDO $db, int $facturaId): ?array {
    try {
        // Obtener datos de la factura
        $stmt = $db->prepare("SELECT * FROM facturas WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $facturaId]);
        $factura = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$factura) {
            error_log("Factura no encontrada: $facturaId");
            return null;
        }

        // Generar HTML de la factura
        $html = buildInvoiceHtml($factura);

        // Intentar generar PDF con DOMPDF
        $pdfContent = null;
        $extension = 'html';

        if (class_exists('Dompdf\Dompdf')) {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->render();
            $pdfContent = $dompdf->output();
            $extension = 'pdf';
        } else {
            // Si no hay DOMPDF, guardar como HTML renderizable
            $pdfContent = $html;
            $extension = 'html';
        }

        // Subir a R2
        $filename = sprintf(
            'invoices/%s/%s.%s',
            date('Y/m'),
            $factura['numero_factura'],
            $extension
        );

        $tempFile = tempnam(sys_get_temp_dir(), 'invoice_');
        file_put_contents($tempFile, $pdfContent);

        $contentType = $extension === 'pdf' ? 'application/pdf' : 'text/html';
        $r2 = new R2Service();
        $relativePath = $r2->uploadFile($tempFile, $filename, $contentType);

        // Limpiar archivo temporal
        @unlink($tempFile);

        return ['pdf_path' => $relativePath];

    } catch (Throwable $e) {
        error_log('Error generando factura PDF: ' . $e->getMessage());
        return null;
    }
}

/**
 * Construye el HTML profesional de la factura.
 */
function buildInvoiceHtml(array $factura): string {
    $numero = htmlspecialchars($factura['numero_factura'] ?? '', ENT_QUOTES, 'UTF-8');
    $fechaEmision = date('d/m/Y H:i', strtotime($factura['fecha_emision'] ?? 'now'));
    $fechaPago = !empty($factura['fecha_pago']) ? date('d/m/Y H:i', strtotime($factura['fecha_pago'])) : '-';

    $emisorNombre = htmlspecialchars($factura['emisor_nombre'] ?? '', ENT_QUOTES, 'UTF-8');
    $emisorEmail = htmlspecialchars($factura['emisor_email'] ?? '', ENT_QUOTES, 'UTF-8');

    $receptorNombre = htmlspecialchars($factura['receptor_nombre'] ?? '', ENT_QUOTES, 'UTF-8');
    $receptorDoc = htmlspecialchars($factura['receptor_documento'] ?? '', ENT_QUOTES, 'UTF-8');
    $receptorEmail = htmlspecialchars($factura['receptor_email'] ?? '', ENT_QUOTES, 'UTF-8');

    $subtotal = number_format(floatval($factura['subtotal'] ?? 0), 0, ',', '.');
    $total = number_format(floatval($factura['total'] ?? 0), 0, ',', '.');
    $comisionPct = floatval($factura['porcentaje_comision'] ?? 0);
    $comisionVal = number_format(floatval($factura['valor_comision'] ?? 0), 0, ',', '.');

    $concepto = htmlspecialchars($factura['concepto'] ?? '', ENT_QUOTES, 'UTF-8');
    $notas = htmlspecialchars($factura['notas'] ?? '', ENT_QUOTES, 'UTF-8');
    $estado = strtoupper(htmlspecialchars($factura['estado'] ?? 'EMITIDA', ENT_QUOTES, 'UTF-8'));
    $moneda = htmlspecialchars($factura['moneda'] ?? 'COP', ENT_QUOTES, 'UTF-8');

    $estadoColor = match($factura['estado'] ?? 'emitida') {
        'pagada' => '#4CAF50',
        'anulada' => '#F44336',
        default => '#FF9800',
    };

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {$numero}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, Helvetica, sans-serif; color: #1a1a2e; background: #fff; padding: 40px; line-height: 1.5; }
        .invoice-container { max-width: 800px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #0a1628 0%, #162240 100%); color: #fff; padding: 30px 40px; display: flex; justify-content: space-between; align-items: center; }
        .header .brand { font-size: 28px; font-weight: 800; letter-spacing: 2px; }
        .header .brand span { color: #00d4ff; }
        .header .invoice-info { text-align: right; font-size: 13px; }
        .header .invoice-info h2 { font-size: 20px; margin-bottom: 4px; color: #00d4ff; }
        .status-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; color: #fff; background: {$estadoColor}; margin-top: 6px; }
        .body-content { padding: 30px 40px; }
        .parties { display: flex; justify-content: space-between; margin-bottom: 30px; gap: 30px; }
        .party { flex: 1; }
        .party h3 { font-size: 11px; text-transform: uppercase; color: #888; letter-spacing: 1px; margin-bottom: 6px; }
        .party .name { font-size: 16px; font-weight: 700; color: #1a1a2e; }
        .party .detail { font-size: 13px; color: #555; margin-top: 2px; }
        .divider { border: none; border-top: 2px solid #f0f0f0; margin: 20px 0; }
        .details-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .details-table th { background: #f5f7fa; text-align: left; padding: 10px 14px; font-size: 11px; text-transform: uppercase; color: #666; letter-spacing: 0.5px; border-bottom: 2px solid #e0e0e0; }
        .details-table td { padding: 12px 14px; font-size: 14px; border-bottom: 1px solid #f0f0f0; }
        .details-table .amount { text-align: right; font-weight: 600; }
        .totals { display: flex; justify-content: flex-end; margin-top: 20px; }
        .totals-box { min-width: 280px; }
        .total-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; }
        .total-row.grand { font-size: 18px; font-weight: 800; color: #1a1a2e; border-top: 2px solid #1a1a2e; padding-top: 12px; margin-top: 8px; }
        .footer { background: #f5f7fa; padding: 20px 40px; text-align: center; font-size: 11px; color: #888; border-top: 1px solid #e0e0e0; }
        .footer .legal { margin-top: 8px; font-style: italic; }
        .notes { background: #fffde7; border-left: 3px solid #ffc107; padding: 12px 16px; margin-top: 20px; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="header">
            <div>
                <div class="brand">VI<span>AX</span></div>
                <div style="font-size: 12px; opacity: 0.7; margin-top: 4px;">Plataforma de Transporte</div>
            </div>
            <div class="invoice-info">
                <h2>FACTURA</h2>
                <div><strong>N°:</strong> {$numero}</div>
                <div><strong>Fecha:</strong> {$fechaEmision}</div>
                <div class="status-badge">{$estado}</div>
            </div>
        </div>

        <div class="body-content">
            <div class="parties">
                <div class="party">
                    <h3>Emisor</h3>
                    <div class="name">{$emisorNombre}</div>
                    <div class="detail">{$emisorEmail}</div>
                    <div class="detail">Plataforma Viax</div>
                </div>
                <div class="party" style="text-align: right;">
                    <h3>Receptor</h3>
                    <div class="name">{$receptorNombre}</div>
                    <div class="detail">NIT/CC: {$receptorDoc}</div>
                    <div class="detail">{$receptorEmail}</div>
                </div>
            </div>

            <hr class="divider">

            <table class="details-table">
                <thead>
                    <tr>
                        <th>Concepto</th>
                        <th style="text-align: right;">Monto ({$moneda})</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{$concepto}</td>
                        <td class="amount">$ {$subtotal}</td>
                    </tr>
                </tbody>
            </table>

            <div class="totals">
                <div class="totals-box">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span>$ {$subtotal} {$moneda}</span>
                    </div>
HTML;

    if ($comisionPct > 0) {
        $html .= <<<HTML
                    <div class="total-row">
                        <span>Comisión ({$comisionPct}%):</span>
                        <span>$ {$comisionVal} {$moneda}</span>
                    </div>
HTML;
    }

    $html .= <<<HTML
                    <div class="total-row grand">
                        <span>TOTAL:</span>
                        <span>$ {$total} {$moneda}</span>
                    </div>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <div class="total-row" style="display: flex; justify-content: space-between;">
                    <span style="font-size: 13px; color: #888;">Fecha de pago:</span>
                    <span style="font-size: 13px; font-weight: 600;">{$fechaPago}</span>
                </div>
            </div>
HTML;

    if (!empty(trim($factura['notas'] ?? ''))) {
        $html .= <<<HTML
            <div class="notes">
                <strong>Notas:</strong> {$notas}
            </div>
HTML;
    }

    $html .= <<<HTML
        </div>

        <div class="footer">
            <div>Este documento es una constancia de pago generada automáticamente por la plataforma Viax.</div>
            <div class="legal">
                Documento electrónico válido. Las facturas son registros permanentes y no pueden ser eliminadas.
                Conserve este documento para sus registros contables.
            </div>
        </div>
    </div>
</body>
</html>
HTML;

    return $html;
}
