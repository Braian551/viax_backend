<?php
/**
 * PdfGenerator - Generación de PDFs profesionales para Viax
 * 
 * Este archivo contiene componentes reutilizables para la generación de PDFs
 * siguiendo el diseño y colores de la aplicación Viax.
 * 
 * Colores de la App:
 * - Primary Blue: #0d6efd
 * - Dark Blue: #0056b3
 * - Dark Text: #212529
 * - Gray Text: #6c757d
 * - Light Gray BG: #f8f9fa
 * - Border Gray: #dee2e6
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/R2Service.php';

class ViaxPdfDocument extends TCPDF {
    public function Footer() {
        $this->SetY(-14);
        $this->SetDrawColor(222, 226, 230);
        $this->Line(15, $this->GetY() - 1, 195, $this->GetY() - 1);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(108, 117, 125);
        $this->Cell(0, 4, 'Viax Technology S.A.S. | viaxcol.online | NIT 902040253-1', 0, 1, 'C');
        $this->Cell(0, 4, 'Este documento es un comprobante oficial de negocio.', 0, 0, 'C');
    }
}

class PdfGenerator {
    
    // App Colors - Matching email and app design
    private const PRIMARY_BLUE = [77, 166, 255];       // #4da6ff - Bright blue like email header
    private const DARK_BLUE = [13, 110, 253];          // #0d6efd - Bootstrap primary
    private const DARK_TEXT = [33, 37, 41];           // #212529
    private const GRAY_TEXT = [108, 117, 125];        // #6c757d
    private const LIGHT_GRAY_BG = [248, 249, 250];    // #f8f9fa
    private const BORDER_GRAY = [222, 226, 230];      // #dee2e6
    private const WHITE = [255, 255, 255];
    private const SUCCESS_GREEN = [25, 135, 84];      // #198754
    private const WARNING_AMBER = [255, 193, 7];      // #ffc107
    
    private $pdf;
    
    public function __construct() {
        date_default_timezone_set('America/Bogota');
        $this->pdf = new ViaxPdfDocument(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->setupDocument();
    }
    
    private function setupDocument() {
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(true);
        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->SetAutoPageBreak(TRUE, 24);
        $this->pdf->SetFont('helvetica', '', 10);
    }
    
    // ========================
    // REUSABLE COMPONENTS
    // ========================
    
    /**
     * Renders the professional header with app logo and title
     */
    private function renderHeader($title, $subtitle = '') {
        $pdf = $this->pdf;
        
        // Header background bar
        $pdf->SetFillColor(...self::PRIMARY_BLUE);
        $pdf->Rect(0, 0, 210, 35, 'F');
        
        // Circular white logo container for all generated PDFs.
        $pdf->SetFillColor(...self::WHITE);
        $pdf->SetDrawColor(230, 236, 245);
        $pdf->Circle(25, 17.5, 11, 0, 360, 'DF');

        // App Logo
        $appLogoPath = __DIR__ . '/../assets/images/logo.png';
        if (file_exists($appLogoPath)) {
            $pdf->Image($appLogoPath, 17, 9.5, 16, 16, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        // Title
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetTextColor(...self::WHITE);
        $pdf->SetXY(40, 10);
        $pdf->Cell(0, 8, $title, 0, 1, 'L');
        
        // Subtitle
        if ($subtitle) {
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetXY(40, 18);
            $pdf->Cell(0, 6, $subtitle, 0, 1, 'L');
        }
        
        $pdf->SetY(42);
        $pdf->SetTextColor(...self::DARK_TEXT);
    }
    
    /**
     * Renders a centered company/entity logo
     */
    private function renderCenteredLogo($logoUrl, $altText = '') {
        $pdf = $this->pdf;
        
        if (empty($logoUrl)) return;
        
        $logoTempFile = $this->fetchImage($logoUrl);
        if ($logoTempFile && file_exists($logoTempFile)) {
            $this->ensureSpace(58);
            // Circular white container (border-radius 50%) for brand/logo consistency.
            $startY = $pdf->GetY();
            $centerX = 105;
            $centerY = $startY + 25;
            $radius = 25;
            $pdf->SetFillColor(...self::WHITE);
            $pdf->SetDrawColor(...self::BORDER_GRAY);
            $pdf->Circle($centerX, $centerY, $radius, 0, 360, 'DF');

            // Image centered within the circle.
            $pdf->Image($logoTempFile, 85, $startY + 5, 40, 40, '', '', '', false, 300, 'C', false, false, 0, false, false, false);
            
            @unlink($logoTempFile);
            $pdf->SetY($startY + 55);
        }
    }
    
    /**
     * Renders a section header
     */
    private function renderSectionHeader($title, $icon = null) {
        $pdf = $this->pdf;
        
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(...self::PRIMARY_BLUE);
        
        // Blue accent line
        $y = $pdf->GetY();
        $pdf->SetFillColor(...self::PRIMARY_BLUE);
        $pdf->Rect(15, $y, 3, 8, 'F');
        
        $pdf->SetX(22);
        $pdf->Cell(0, 8, $title, 0, 1, 'L');
        
        $pdf->SetTextColor(...self::DARK_TEXT);
        $pdf->Ln(3);
    }
    
    /**
     * Renders a detail row (label: value)
     */
    private function renderDetailRow($label, $value, $isLast = false) {
        $pdf = $this->pdf;
        
        if (empty($value) || $value === 'N/A') return;
        
        $pdf->SetFont('helvetica', '', 10);
        
        // Label
        $pdf->SetTextColor(...self::GRAY_TEXT);
        $pdf->SetX(22);
        $pdf->Cell(45, 7, $label, 0, 0, 'L');
        
        // Value
        $pdf->SetTextColor(...self::DARK_TEXT);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 7, $value, 0, 1, 'L');
        
        // Separator line (except last)
        if (!$isLast) {
            $y = $pdf->GetY();
            $pdf->SetDrawColor(...self::BORDER_GRAY);
            $pdf->Line(22, $y, 190, $y);
        }
    }
    
    /**
     * Renders a status badge
     */
    private function renderStatusBadge($status, $type = 'pending') {
        $pdf = $this->pdf;
        $this->ensureSpace(34);
        $pdf->Ln(10);
        
        $bgColor = self::LIGHT_GRAY_BG;
        $textColor = self::GRAY_TEXT;
        $borderColor = self::BORDER_GRAY;
        $statusLabel = $status;
        
        switch ($type) {
            case 'pending':
                $bgColor = [255, 243, 205];     // Light amber
                $textColor = [133, 100, 4];      // Dark amber text
                $borderColor = self::WARNING_AMBER;
                break;
            case 'approved':
                $bgColor = [209, 231, 221];     // Light green
                $textColor = self::SUCCESS_GREEN;
                $borderColor = self::SUCCESS_GREEN;
                break;
            case 'rejected':
                $bgColor = [248, 215, 218];     // Light red
                $textColor = [114, 28, 36];
                $borderColor = [220, 53, 69];
                break;
        }
        
        $startY = $pdf->GetY();
        
        // Badge container
        $pdf->SetFillColor(...$bgColor);
        $pdf->SetDrawColor(...$borderColor);
        $pdf->RoundedRect(15, $startY, 180, 20, 3, '1111', 'DF');
        
        // Status text
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(...$textColor);
        $pdf->SetXY(20, $startY + 3);
        $pdf->Cell(0, 7, 'Estado: ' . $statusLabel, 0, 1, 'L');
        
        // Timestamp
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(20, $startY + 10);
        $pdf->Cell(0, 7, 'Documento generado el ' . date('d/m/Y \a \l\a\s h:i A') . ' (America/Bogota)', 0, 1, 'L');
        
        $pdf->SetTextColor(...self::DARK_TEXT);
        $pdf->SetY($startY + 25);
    }
    
    /**
     * Renders the footer with branding
     */
    private function renderFooter() {
        // Footer is handled by ViaxPdfDocument::Footer() to avoid blank pages and overflow.
        return;
    }
    
    // ========================
    // PDF GENERATORS
    // ========================
    
    /**
     * Generates a professional company registration PDF
     */
    public function generateRegistrationPdf($data) {
        $pdf = $this->pdf;
        
        // Document metadata
        $pdf->SetCreator('Viax Platform');
        $pdf->SetAuthor('Viax');
        $pdf->SetTitle('Registro de Empresa - ' . ($data['nombre_empresa'] ?? 'Empresa'));
        $pdf->SetSubject('Comprobante de Registro');
        
        $pdf->AddPage();
        
        // 1. Header
        $this->renderHeader('Registro de Empresa', 'Comprobante de Solicitud de Vinculación');
        
        // 2. Company Logo (if exists)
        if (!empty($data['logo_url'])) {
            $this->renderCenteredLogo($data['logo_url']);
        }
        
        // 3. Company Information Section
        $this->renderSectionHeader('Información de la Empresa');
        
        $this->renderDetailRow('Nombre Comercial', $data['nombre_empresa'] ?? '');
        $this->renderDetailRow('Razón Social', $data['razon_social'] ?? '');
        $this->renderDetailRow('NIT', $data['nit'] ?? '');
        $this->renderDetailRow('Email Corporativo', $data['email'] ?? '');
        $this->renderDetailRow('Teléfono', $data['telefono'] ?? '');
        
        if (!empty($data['telefono_secundario'])) {
            $this->renderDetailRow('Teléfono Secundario', $data['telefono_secundario']);
        }
        
        $ubicacion = trim(($data['municipio'] ?? '') . ', ' . ($data['departamento'] ?? ''), ', ');
        $this->renderDetailRow('Ubicación', $ubicacion);
        $this->renderDetailRow('Dirección', $data['direccion'] ?? '');
        
        $vehiculos = $this->formatVehicles($data['tipos_vehiculo'] ?? []);
        $this->renderDetailRow('Tipos de Vehículo', $vehiculos, true);
        
        // 4. Representative Section
        $this->renderSectionHeader('Representante Legal');
        
        $this->renderDetailRow('Nombre Completo', $data['representante_nombre'] ?? '');
        
        if (!empty($data['representante_telefono'])) {
            $this->renderDetailRow('Teléfono Personal', $data['representante_telefono']);
        }
        if (!empty($data['representante_email'])) {
            $this->renderDetailRow('Email Personal', $data['representante_email'], true);
        }
        
        // 5. Status Badge
        $this->renderStatusBadge('Pendiente de Aprobación', 'pending');
        
        // 6. Footer
        $this->renderFooter();
        
        // Output to temp file
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'viax_reg_') . '.pdf';
        $pdf->Output($tempPdfPath, 'F');
        
        return $tempPdfPath;
    }
    
    /**
     * Generates a professional Activity/Operational Report PDF
     */
    public function generateActivityReport($data) {
        $pdf = $this->pdf;
        
        $pdf->SetCreator('Viax Platform');
        $pdf->SetAuthor('Viax');
        $pdf->SetTitle('Reporte de Actividad - ' . ($data['empresa_nombre'] ?? 'Empresa'));
        
        $pdf->AddPage();

        $periodoLabel = [
            '7d' => 'Últimos 7 días',
            '30d' => 'Últimos 30 días',
            '90d' => 'Últimos 3 meses',
            '1y' => 'Último año',
            'all' => 'Histórico total'
        ][$data['periodo'] ?? '30d'] ?? 'Periodo personalizado';

        $empresaNombre = $data['empresa_nombre'] ?? 'Empresa de Transporte';
        $generadoEn = $data['generated_at'] ?? date('Y-m-d H:i:s');

        $tripStats = $data['trip_stats'] ?? [];
        $earningsStats = $data['earnings_stats'] ?? [];
        $topDrivers = $data['top_drivers'] ?? [];
        $vehicleDistribution = $data['vehicle_distribution'] ?? [];
        $recentTrips = $data['recent_trips'] ?? [];
        $companyLogoUrl = $data['company_logo_url'] ?? null;

        // Header ejecutivo
        $this->renderHeader('Reporte Ejecutivo de Operación', $periodoLabel);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(...self::GRAY_TEXT);
        $pdf->SetX(15);
        $pdf->Cell(0, 6, 'Empresa: ' . $empresaNombre . ' | Generado: ' . $this->formatDateTime($generadoEn), 0, 1, 'L');
        $pdf->SetTextColor(...self::DARK_TEXT);
        $pdf->Ln(2);

        if (!empty($companyLogoUrl)) {
            $this->renderSectionHeader('Identidad de Marca');
            $this->renderCenteredLogo($companyLogoUrl, 'Logo de empresa');
        }

        // KPI cards
        $kpis = [
            ['title' => 'Viajes Totales', 'value' => number_format((int)($tripStats['total'] ?? 0), 0, ',', '.'), 'accent' => [13, 110, 253]],
            ['title' => 'Tasa de Éxito', 'value' => number_format((float)($tripStats['tasa_completados'] ?? 0), 1, ',', '.') . '%', 'accent' => [25, 135, 84]],
            ['title' => 'GMV', 'value' => $this->formatMoney($earningsStats['ingresos_totales'] ?? 0), 'accent' => [33, 150, 243]],
            ['title' => 'Ganancia Neta', 'value' => $this->formatMoney($earningsStats['ganancia_neta'] ?? 0), 'accent' => [255, 152, 0]],
        ];
        $this->renderKpiCards($kpis);

        $this->renderSectionHeader('Indicadores Operativos');
        $this->renderDetailRow('Viajes Completados', number_format((int)($tripStats['completados'] ?? 0), 0, ',', '.'));
        $this->renderDetailRow('Viajes Cancelados', number_format((int)($tripStats['cancelados'] ?? 0), 0, ',', '.'));
        $this->renderDetailRow('Viajes En Progreso', number_format((int)($tripStats['en_progreso'] ?? 0), 0, ',', '.'));
        $this->renderDetailRow('Distancia Total', number_format((float)($tripStats['distancia_total'] ?? 0), 2, ',', '.') . ' km');
        $this->renderDetailRow('Distancia Promedio', number_format((float)($tripStats['distancia_promedio'] ?? 0), 2, ',', '.') . ' km');
        $this->renderDetailRow('Duración Promedio', number_format((float)($tripStats['duracion_promedio'] ?? 0), 0, ',', '.') . ' min', true);

        // Tabla Top Conductores
        if (!empty($topDrivers)) {
            $this->renderSectionHeader('Top Conductores');
            $this->renderSimpleTable(
                ['#', 'Conductor', 'Viajes', 'Rating', 'Producción'],
                array_map(function($d, $index) {
                    return [
                        (string)($index + 1),
                        $d['nombre'] ?? 'N/A',
                        (string)($d['total_viajes'] ?? 0),
                        number_format((float)($d['rating'] ?? 0), 1, ',', '.'),
                        $this->formatMoney($d['ingresos'] ?? 0),
                    ];
                }, $topDrivers, array_keys($topDrivers)),
                [10, 75, 20, 25, 40]
            );
        }

        // Tabla Flota
        if (!empty($vehicleDistribution)) {
            $this->renderSectionHeader('Composición de Flota y Producción');
            $this->renderSimpleTable(
                ['Tipo', 'Servicios', '% Mix', 'Ingresos'],
                $this->buildVehicleRows($vehicleDistribution),
                [70, 30, 25, 45]
            );
        }

        // Segunda página para bitácora reciente
        if (!empty($recentTrips)) {
            $pdf->AddPage();
            $this->renderHeader('Bitácora Reciente de Servicios', $periodoLabel);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(...self::GRAY_TEXT);
            $pdf->SetX(15);
            $pdf->Cell(0, 6, 'Últimos ' . count($recentTrips) . ' viajes consolidados de la operación', 0, 1, 'L');
            $pdf->SetTextColor(...self::DARK_TEXT);
            $pdf->Ln(2);

            $tripRows = [];
            foreach ($recentTrips as $trip) {
                $route = trim(($trip['origen'] ?? '') . ' -> ' . ($trip['destino'] ?? ''));
                if (strlen($route) > 75) {
                    $route = substr($route, 0, 72) . '...';
                }

                $tripRows[] = [
                    $this->formatShortDate($trip['fecha'] ?? null),
                    $trip['conductor'] ?? 'N/A',
                    $trip['tipo_operacion_nombre'] ?? ucfirst((string)($trip['tipo_operacion'] ?? 'otro')),
                    ucfirst((string)($trip['estado'] ?? '')), 
                    $this->formatMoney($trip['valor'] ?? 0),
                    $route,
                ];
            }

            $this->renderSimpleTable(
                ['Fecha', 'Conductor', 'Tipo', 'Estado', 'Valor', 'Ruta'],
                $tripRows,
                [22, 35, 22, 20, 24, 57],
                7
            );
        }

        $this->renderStatusBadge('Reporte consolidado y auditado', 'approved');
        
        // Output to temp file
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'viax_rep_') . '.pdf';
        $pdf->Output($tempPdfPath, 'F');
        
        return $tempPdfPath;
    }

    // ========================
    // UTILITY METHODS
    // ========================

    private function formatMoney($value) {
        return '$' . number_format((float)($value ?? 0), 0, ',', '.');
    }

    private function formatDateTime($value) {
        if (empty($value)) {
            return date('d/m/Y h:i A');
        }

        try {
            return (new DateTime($value))->format('d/m/Y h:i A');
        } catch (Exception $e) {
            return date('d/m/Y h:i A');
        }
    }

    private function formatShortDate($value) {
        if (empty($value)) {
            return '-';
        }

        try {
            return (new DateTime($value))->format('d/m H:i');
        } catch (Exception $e) {
            return '-';
        }
    }

    private function renderKpiCards($kpis) {
        $pdf = $this->pdf;
        $startX = 15;
        $startY = $pdf->GetY();
        $gap = 4;
        $cardWidth = (180 - ($gap * 3)) / 4;
        $cardHeight = 24;

        foreach ($kpis as $index => $kpi) {
            $x = $startX + (($cardWidth + $gap) * $index);
            $accent = $kpi['accent'] ?? self::PRIMARY_BLUE;

            $pdf->SetFillColor(...self::LIGHT_GRAY_BG);
            $pdf->SetDrawColor(...self::BORDER_GRAY);
            $pdf->RoundedRect($x, $startY, $cardWidth, $cardHeight, 2, '1111', 'DF');

            $pdf->SetFillColor(...$accent);
            $pdf->Rect($x, $startY, 2, $cardHeight, 'F');

            $pdf->SetXY($x + 4, $startY + 3);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(...self::GRAY_TEXT);
            $pdf->Cell($cardWidth - 6, 5, $kpi['title'] ?? '', 0, 1, 'L');

            $pdf->SetXY($x + 4, $startY + 10);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetTextColor(...self::DARK_TEXT);
            $pdf->Cell($cardWidth - 6, 8, $kpi['value'] ?? '', 0, 1, 'L');
        }

        $pdf->SetY($startY + $cardHeight + 6);
    }

    private function renderSimpleTable($headers, $rows, $colWidths, $rowHeight = 8) {
        $pdf = $this->pdf;

        if (empty($rows)) {
            return;
        }

        $startX = 15;

        // Header row
        $pdf->SetFillColor(...self::PRIMARY_BLUE);
        $pdf->SetTextColor(...self::WHITE);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetX($startX);

        foreach ($headers as $idx => $header) {
            $pdf->Cell($colWidths[$idx], $rowHeight, $header, 1, 0, 'L', true);
        }
        $pdf->Ln();

        // Body rows
        $pdf->SetFont('helvetica', '', 8);
        $fill = false;
        foreach ($rows as $row) {
            if ($pdf->GetY() > 252) {
                $pdf->AddPage();
                $pdf->SetX($startX);
                $pdf->SetFillColor(...self::PRIMARY_BLUE);
                $pdf->SetTextColor(...self::WHITE);
                $pdf->SetFont('helvetica', 'B', 8);
                foreach ($headers as $idx => $header) {
                    $pdf->Cell($colWidths[$idx], $rowHeight, $header, 1, 0, 'L', true);
                }
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 8);
            }

            $pdf->SetX($startX);
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
            $pdf->SetTextColor(...self::DARK_TEXT);

            foreach ($row as $idx => $cell) {
                $text = $this->fitTextToCell((string)$cell, (float)$colWidths[$idx], 8);
                $pdf->Cell($colWidths[$idx], $rowHeight, $text, 1, 0, 'L', true);
            }
            $pdf->Ln();
            $fill = !$fill;
        }

        $pdf->Ln(4);
    }

    private function buildVehicleRows($vehicleDistribution) {
        $totalTrips = 0;
        foreach ($vehicleDistribution as $item) {
            $totalTrips += (int)($item['viajes'] ?? 0);
        }

        $rows = [];
        foreach ($vehicleDistribution as $item) {
            $viajes = (int)($item['viajes'] ?? 0);
            $mix = $totalTrips > 0 ? round(($viajes / $totalTrips) * 100, 1) : 0;
            $rows[] = [
                $item['nombre'] ?? 'N/A',
                number_format($viajes, 0, ',', '.'),
                number_format($mix, 1, ',', '.') . '%',
                $this->formatMoney($item['ingresos'] ?? 0),
            ];
        }

        return $rows;
    }

    private function ensureSpace($requiredHeight) {
        $pdf = $this->pdf;
        $available = $pdf->getPageHeight() - $pdf->GetY() - 24;
        if ($available < $requiredHeight) {
            $pdf->AddPage();
        }
    }

    private function fitTextToCell($text, $cellWidth, $fontSize = 8) {
        $pdf = $this->pdf;
        $raw = trim((string)$text);
        if ($raw === '') {
            return '';
        }

        // Keep a little horizontal padding so text never bleeds into adjacent columns.
        $maxTextWidth = max(1.0, $cellWidth - 2.0);

        // Ensure the width check uses the same font configured for body rows.
        $pdf->SetFont('helvetica', '', $fontSize);

        if ($pdf->GetStringWidth($raw) <= $maxTextWidth) {
            return $raw;
        }

        $ellipsis = '...';
        $ellipsisWidth = $pdf->GetStringWidth($ellipsis);
        if ($ellipsisWidth >= $maxTextWidth) {
            return '.';
        }

        $len = function_exists('mb_strlen') ? mb_strlen($raw, 'UTF-8') : strlen($raw);
        while ($len > 1) {
            $len--;
            $candidate = function_exists('mb_substr')
                ? mb_substr($raw, 0, $len, 'UTF-8')
                : substr($raw, 0, $len);
            $candidate = rtrim($candidate) . $ellipsis;

            if ($pdf->GetStringWidth($candidate) <= $maxTextWidth) {
                return $candidate;
            }
        }

        return $ellipsis;
    }
    
    private function fetchImage($urlOrPath) {
        if (empty($urlOrPath)) return null;

        // If it's a URL, download it with timeout
        if (filter_var($urlOrPath, FILTER_VALIDATE_URL)) {
             $ctx = stream_context_create([
                 'http' => ['timeout' => 5],
                 'https' => ['timeout' => 5]
             ]);
             
             $content = @file_get_contents($urlOrPath, false, $ctx);
             if ($content) {
                 $path = tempnam(sys_get_temp_dir(), 'img_dl');
                 file_put_contents($path, $content);
                 return $path;
             }
        } 
        // R2 key
        else {
            try {
                $r2 = new R2Service();
                $fileData = $r2->getFile($urlOrPath);
                if ($fileData && !empty($fileData['content'])) {
                     $path = tempnam(sys_get_temp_dir(), 'img_r2');
                     file_put_contents($path, $fileData['content']);
                     return $path;
                }
            } catch (Exception $e) {
                error_log("PDF Gen: Failed to fetch image (R2): " . $e->getMessage());
            }
        }
        return null;
    }
    
    private function formatVehicles($types) {
        if (empty($types)) return 'N/A';
        
        if (is_string($types)) {
            $decoded = json_decode($types, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $types = $decoded;
            } else {
                $types = explode(',', str_replace(['[',']','"'], '', $types)); 
            }
        }
        
        if (is_array($types)) {
             return implode(', ', array_map('ucfirst', array_map('trim', $types)));
        }
        return ucfirst($types);
    }
}
