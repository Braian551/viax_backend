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
        $this->pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->setupDocument();
    }
    
    private function setupDocument() {
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->SetAutoPageBreak(TRUE, 20);
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
        
        // App Logo (white/light version would be ideal, but we use what we have)
        $appLogoPath = __DIR__ . '/../assets/images/logo.png';
        if (file_exists($appLogoPath)) {
            $pdf->Image($appLogoPath, 15, 7, 20, 20, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
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
            // Centered container with light border
            $startY = $pdf->GetY();
            $pdf->SetFillColor(...self::LIGHT_GRAY_BG);
            $pdf->SetDrawColor(...self::BORDER_GRAY);
            $pdf->RoundedRect(75, $startY, 60, 50, 3, '1111', 'DF');
            
            // Image centered within
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
        $pdf->Cell(0, 7, 'Documento generado el ' . date('d/m/Y \a \l\a\s h:i A'), 0, 1, 'L');
        
        $pdf->SetTextColor(...self::DARK_TEXT);
        $pdf->SetY($startY + 25);
    }
    
    /**
     * Renders the footer with branding
     */
    private function renderFooter() {
        $pdf = $this->pdf;
        
        $footerY = 280;
        
        // Footer line
        $pdf->SetDrawColor(...self::BORDER_GRAY);
        $pdf->Line(15, $footerY - 5, 195, $footerY - 5);
        
        // Footer text
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(...self::GRAY_TEXT);
        $pdf->SetXY(15, $footerY);
        $pdf->Cell(0, 5, 'Viax - Viaja fácil, llega rápido | www.viax.com', 0, 0, 'C');
        
        $pdf->SetXY(15, $footerY + 5);
        $pdf->Cell(0, 5, 'Este documento es un comprobante oficial de registro.', 0, 0, 'C');
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
    
    // ========================
    // UTILITY METHODS
    // ========================
    
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
