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

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/R2Service.php';
require_once __DIR__ . '/../vendor/autoload.php';

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

        if (($factura['receptor_tipo'] ?? '') === 'empresa' && !empty($factura['receptor_id'])) {
            $stmtLogo = $db->prepare("SELECT logo_url FROM empresas_transporte WHERE id = :id LIMIT 1");
            $stmtLogo->execute([':id' => intval($factura['receptor_id'])]);
            $logo = $stmtLogo->fetchColumn();
            if (!empty($logo)) {
                $factura['receptor_logo_url'] = $logo;
            }
        }

        // Generar HTML de la factura
        $html = buildInvoiceHtml($factura);

        // Intentar generar PDF con DOMPDF
        $pdfContent = null;
        $extension = 'html';

        if (class_exists('Dompdf\\Dompdf')) {
            $dompdfClass = 'Dompdf\\Dompdf';
            $dompdf = new $dompdfClass(['isRemoteEnabled' => false]);
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

        // Subir a R2 con versión para evitar servir archivos cacheados antiguos.
        $versionSuffix = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('YmdHis');
        $coNow = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')));
        $filename = sprintf(
            'invoices/%s/%s_%s.%s',
            $coNow->format('Y/m'),
            $factura['numero_factura'],
            $versionSuffix,
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
 * Obtiene el logo corporativo como data URI para incrustarlo en el PDF.
 */
function getInvoiceLogoDataUri(): string {
    $logoPath = __DIR__ . '/../assets/images/logo.png';
    if (!file_exists($logoPath)) {
        return '';
    }

    $content = @file_get_contents($logoPath);
    if ($content === false) {
        return '';
    }

    return 'data:image/png;base64,' . base64_encode($content);
}

function getR2ImageDataUri(?string $r2Ref): string {
    $r2Ref = trim((string)$r2Ref);
    if ($r2Ref === '') {
        return '';
    }

    try {
        $key = $r2Ref;
        if (preg_match('/[?&]key=([^&]+)/', $r2Ref, $matches)) {
            $key = urldecode($matches[1]);
        }

        if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
            $parts = parse_url($key);
            $key = ltrim((string)($parts['path'] ?? ''), '/');
        }

        $key = ltrim($key, '/');
        if ($key === '') {
            return '';
        }

        $r2 = new R2Service();
        $file = $r2->getFile($key);
        if (!$file || empty($file['content'])) {
            return '';
        }

        $mime = !empty($file['type']) ? $file['type'] : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($file['content']);
    } catch (Throwable $e) {
        error_log('No se pudo incrustar logo receptor en factura: ' . $e->getMessage());
        return '';
    }
}

function formatDateTimeCo(?string $value): string {
    if (empty($value)) {
        return '-';
    }

    try {
        $clean = trim((string)$value);
        if ($clean === '') {
            return '-';
        }

        // Regla global: timestamps sin zona explícita se interpretan en UTC y se convierten a Colombia.
        if (preg_match('/(Z|[+-]\d{2}:\d{2})$/i', $clean) === 1) {
            $dt = new DateTimeImmutable($clean);
        } else {
            $dt = new DateTimeImmutable($clean, new DateTimeZone('UTC'));
        }

        $dt = $dt->setTimezone(new DateTimeZone('America/Bogota'));
        $ampm = strtolower($dt->format('a')) === 'am' ? 'a. m.' : 'p. m.';
        return $dt->format('d/m/Y h:i') . ' ' . $ampm;
    } catch (Throwable $e) {
        return (string)$value;
    }
}

/**
 * Construye el HTML profesional de la factura.
 */
function buildInvoiceHtml(array $factura): string {
        $platformLegalName = 'VIAX TECHONOLOGY S.A.S';

    $safe = static function ($value, $fallback = '-') {
        $txt = trim((string)($value ?? ''));
        if ($txt === '') {
            $txt = $fallback;
        }
        return htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
    };

    $numero = htmlspecialchars($factura['numero_factura'] ?? '', ENT_QUOTES, 'UTF-8');
    $fechaEmision = formatDateTimeCo($factura['fecha_emision'] ?? 'now');
    $fechaPago = !empty($factura['fecha_pago']) ? formatDateTimeCo($factura['fecha_pago']) : '-';

    $emisorNombre = $safe($factura['emisor_nombre'] ?? '', 'Emisor no configurado');
    $emisorEmail = $safe($factura['emisor_email'] ?? '', 'No registrado');

    $receptorNombre = $safe($factura['receptor_nombre'] ?? '', 'Receptor no registrado');
    $receptorDoc = $safe($factura['receptor_documento'] ?? '', 'No registrado');
    $receptorEmail = $safe($factura['receptor_email'] ?? '', 'No registrado');

    $subtotalRaw = floatval($factura['subtotal'] ?? 0);
    $totalRaw = floatval($factura['total'] ?? 0);
    $comisionPct = floatval($factura['porcentaje_comision'] ?? 0);
    $comisionRaw = floatval($factura['valor_comision'] ?? 0);

    // Compatibilidad: facturas antiguas podían guardar valor_comision = 0.
    // Si existe porcentaje y total, usar total como comisión para mostrar correctamente.
    if ($comisionRaw <= 0 && $comisionPct > 0 && $totalRaw > 0) {
        $comisionRaw = $totalRaw;
        if ($subtotalRaw <= 0) {
            $subtotalRaw = round($totalRaw / ($comisionPct / 100), 2);
        }
    }

    $subtotal = number_format($subtotalRaw, 0, ',', '.');
    $total = number_format($totalRaw, 0, ',', '.');
    $comisionVal = number_format($comisionRaw, 0, ',', '.');

    $concepto = htmlspecialchars($factura['concepto'] ?? '', ENT_QUOTES, 'UTF-8');
    $notas = htmlspecialchars($factura['notas'] ?? '', ENT_QUOTES, 'UTF-8');
    $estado = strtoupper(htmlspecialchars($factura['estado'] ?? 'EMITIDA', ENT_QUOTES, 'UTF-8'));
    $moneda = htmlspecialchars($factura['moneda'] ?? 'COP', ENT_QUOTES, 'UTF-8');
    $tipo = htmlspecialchars($factura['tipo'] ?? 'empresa_admin', ENT_QUOTES, 'UTF-8');
    $tipoLabel = $tipo === 'empresa_admin' ? 'Empresa a Plataforma' : 'Conductor a Empresa';
    $emisorTipo = strtoupper($safe($factura['emisor_tipo'] ?? '-', '-'));
    $receptorTipo = strtoupper($safe($factura['receptor_tipo'] ?? '-', '-'));
    $emisorDocumento = $safe($factura['emisor_documento'] ?? '-', '-');
    $pagoReferenciaId = htmlspecialchars((string)($factura['pago_referencia_id'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $pagoReferenciaTipoRaw = strtolower(trim((string)($factura['pago_referencia_tipo'] ?? '')));
    $pagoReferenciaTipoLabel = match ($pagoReferenciaTipoRaw) {
        'pago_empresa', 'pago_empresa_plataforma' => 'Pago empresa a plataforma',
        'pago_conductor', 'pago_conductor_empresa' => 'Pago conductor a empresa',
        '', '-' => 'Pago registrado',
        default => ucwords(str_replace('_', ' ', $pagoReferenciaTipoRaw)),
    };
    $pagoReferenciaTipo = htmlspecialchars($pagoReferenciaTipoLabel, ENT_QUOTES, 'UTF-8');
    $reporteId = htmlspecialchars((string)($factura['reporte_id'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $createdAt = !empty($factura['created_at']) ? formatDateTimeCo($factura['created_at']) : '-';
    $logoDataUri = getInvoiceLogoDataUri();
    $receptorLogoDataUri = getR2ImageDataUri($factura['receptor_logo_url'] ?? '');

    $estadoColor = match($factura['estado'] ?? 'emitida') {
        'pagada' => '#4CAF50',
        'anulada' => '#F44336',
        default => '#FF9800',
    };

    $headerBrand = $logoDataUri !== ''
        ? '<img src="' . $logoDataUri . '" alt="Viax" style="height:56px;" />'
        : '<div style="font-size:30px;font-weight:900;letter-spacing:2px;color:#0A1628;">VI<span style="color:#00D4FF;">AX</span></div>';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {$numero}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'DejaVu Sans', Arial, sans-serif; color: #0A1628; background: #F4F7FB; padding: 20px; line-height: 1.35; }
        .invoice-container { max-width: 860px; margin: 0 auto; border: 1px solid #D7E1EE; border-radius: 12px; overflow: hidden; background: #FFFFFF; }
        .header { background: #0A1628; color: #FFFFFF; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
        .brand-wrap { display: table; }
        .brand-sub { font-size: 11px; opacity: 0.8; letter-spacing: 0.4px; }
        .brand-title { font-size: 22px; font-weight: 900; letter-spacing: 1.4px; font-family: 'Verdana', 'Arial Black', 'DejaVu Sans', Arial, sans-serif; }
        .header .invoice-info { text-align: right; font-size: 12px; }
        .header .invoice-info h2 { font-size: 24px; margin-bottom: 4px; color: #00D4FF; letter-spacing: 1px; }
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 16px; font-size: 10px; font-weight: 700; color: #FFFFFF; background: {$estadoColor}; margin-top: 8px; letter-spacing: 0.5px; }
        .body-content { padding: 16px 20px; }
        .meta-grid { margin-top: 14px; display: table; width: 100%; border-collapse: collapse; background: #F8FBFF; border: 1px solid #DDE7F3; border-radius: 8px; }
        .meta-row { display: table-row; }
        .meta-cell { display: table-cell; padding: 8px 10px; border-bottom: 1px solid #E6EEF8; font-size: 11px; }
        .meta-key { color: #5F7188; width: 22%; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .meta-val { color: #0A1628; font-weight: 600; }
        .parties { width: 100%; border-collapse: separate; border-spacing: 14px 0; margin-bottom: 10px; }
        .party { vertical-align: top; width: 50%; background: #FFFFFF; border: 1px solid #E5EDF7; border-radius: 8px; padding: 10px 12px; }
        .party h3 { font-size: 11px; text-transform: uppercase; color: #627A95; letter-spacing: 1px; margin-bottom: 6px; }
        .party .name { font-size: 15px; font-weight: 800; color: #0A1628; }
        .party .detail { font-size: 12px; color: #39506C; margin-top: 2px; }
        .divider { border: none; border-top: 1px solid #DDE7F3; margin: 18px 0; }
        .details-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        .details-table th { background: #EEF4FC; text-align: left; padding: 11px 14px; font-size: 11px; text-transform: uppercase; color: #44617F; letter-spacing: 0.5px; border-bottom: 1px solid #D7E1EE; }
        .details-table td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #EAF1F9; }
        .details-table .amount { text-align: right; font-weight: 600; }
        .totals { display: flex; justify-content: flex-end; margin-top: 10px; }
        .totals-box { min-width: 310px; background: #F8FBFF; border: 1px solid #DDE7F3; border-radius: 8px; padding: 10px 14px; }
        .total-row { display: flex; justify-content: space-between; padding: 7px 0; font-size: 13px; color: #274766; }
        .total-row.grand { font-size: 18px; font-weight: 800; color: #0A1628; border-top: 1px solid #B7CAE0; padding-top: 11px; margin-top: 8px; }
        .footer { background: #F3F7FC; padding: 12px 18px; text-align: center; font-size: 10px; color: #56708D; border-top: 1px solid #DDE7F3; }
        .footer .legal { margin-top: 8px; font-style: italic; }
        .notes { background: #FFF9E8; border-left: 3px solid #F5B400; padding: 11px 14px; margin-top: 18px; border-radius: 6px; font-size: 12px; color: #5E4A20; }
        .receiver-logo { width: 54px; height: 54px; border-radius: 10px; border: 1px solid #D7E1EE; object-fit: cover; display: inline-block; margin-bottom: 6px; }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="header">
            <div class="brand-wrap">
                {$headerBrand}
                <div>
                    <div class="brand-title">VIAX</div>
                    <div class="brand-sub">Plataforma de Transporte</div>
                </div>
            </div>
            <div class="invoice-info">
                <h2>FACTURA</h2>
                <div><strong>N°:</strong> {$numero}</div>
                <div><strong>Fecha:</strong> {$fechaEmision}</div>
                <div class="status-badge">{$estado}</div>
            </div>
        </div>

        <div class="body-content">
            <div class="meta-grid">
                <div class="meta-row">
                    <div class="meta-cell meta-key">Tipo de factura</div>
                    <div class="meta-cell meta-val">{$tipoLabel}</div>
                    <div class="meta-cell meta-key">Estado</div>
                    <div class="meta-cell meta-val">{$estado}</div>
                </div>
                <div class="meta-row">
                    <div class="meta-cell meta-key">Ref. Pago</div>
                    <div class="meta-cell meta-val">{$pagoReferenciaTipo} #{$pagoReferenciaId}</div>
                    <div class="meta-cell meta-key">Ref. Reporte</div>
                    <div class="meta-cell meta-val">{$reporteId}</div>
                </div>
                <div class="meta-row">
                    <div class="meta-cell meta-key">Emitida en</div>
                    <div class="meta-cell meta-val">{$fechaEmision}</div>
                    <div class="meta-cell meta-key">Creada en sistema</div>
                    <div class="meta-cell meta-val">{$createdAt}</div>
                </div>
            </div>

            <div style="height:8px;"></div>
            <table class="parties">
                <tr>
                <td class="party">
                    <h3>Emisor</h3>
                    <div class="name">{$emisorNombre}</div>
                    <div class="detail">Tipo: {$emisorTipo}</div>
                    <div class="detail">Documento: {$emisorDocumento}</div>
                    <div class="detail">Correo: {$emisorEmail}</div>
                    <div class="detail">Operador: {$platformLegalName}</div>
                </td>
                <td class="party" style="text-align: right;">
                    <h3>Receptor</h3>
HTML;

    if ($receptorLogoDataUri !== '') {
        $html .= '<img src="' . $receptorLogoDataUri . '" alt="Logo receptor" class="receiver-logo" />';
    }

    $html .= <<<HTML
                    <div class="name">{$receptorNombre}</div>
                    <div class="detail">Tipo: {$receptorTipo}</div>
                    <div class="detail">NIT/CC: {$receptorDoc}</div>
                    <div class="detail">Correo: {$receptorEmail}</div>
                </td>
                </tr>
            </table>

            <hr class="divider">

            <table class="details-table">
                <thead>
                    <tr>
                        <th>Concepto</th>
                        <th style="text-align: right; width: 140px;">Comisión</th>
                        <th style="text-align: right;">Monto ({$moneda})</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{$concepto}</td>
                        <td class="amount">{$comisionPct}%</td>
                        <td class="amount">$ {$subtotal}</td>
                    </tr>
                </tbody>
            </table>

            <div class="totals">
                <div class="totals-box">
                    <div class="total-row">
                        <span>Base del periodo:</span>
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
            <div>Factura electronica de cobro generada por {$platformLegalName} para soporte contable y tributario en Colombia.</div>
            <div class="legal">
                Documento electronico valido. Las facturas son registros permanentes y no pueden ser eliminadas.
                Conserve este documento para sus registros contables y de cumplimiento tributario.
            </div>
        </div>
    </div>
</body>
</html>
HTML;

    return $html;
}
