<?php
/**
 * Migración: Recalcular saldo_pendiente de empresas
 * 
 * Este script:
 * 1. Calcula comision_admin_valor para viajes que no lo tengan
 * 2. Recalcula el saldo_pendiente basándose en viajes y pagos
 * 
 * Fórmula: saldo_pendiente = SUM(comision_admin_valor de viajes) - SUM(pagos realizados)
 */

header('Content-Type: text/html; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<pre>\n";
    echo "=== MIGRACIÓN: Recalcular Comisiones y Saldo Pendiente ===\n\n";
    
    $db->beginTransaction();
    
    // PASO 0: Actualizar viajes finalizados que no tienen comision_admin_valor calculada
    echo "--- PASO 0: Calcular comision_admin_valor para viajes históricos ---\n";
    
    $viajesSinComision = $db->query("
        SELECT 
            vrt.id,
            vrt.comision_plataforma_valor,
            et.comision_admin_porcentaje
        FROM viaje_resumen_tracking vrt
        JOIN empresas_transporte et ON et.id = vrt.empresa_id
        JOIN solicitudes_servicio sv ON sv.id = vrt.solicitud_id
        WHERE sv.estado = 'completada'
          AND (vrt.comision_admin_valor IS NULL OR vrt.comision_admin_valor = 0)
          AND vrt.comision_plataforma_valor > 0
          AND et.comision_admin_porcentaje > 0
    ");
    $viajesPorActualizar = $viajesSinComision->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Viajes sin comision_admin_valor: " . count($viajesPorActualizar) . "\n";
    
    $viajesActualizados = 0;
    foreach ($viajesPorActualizar as $viaje) {
        $comisionAdminValor = $viaje['comision_plataforma_valor'] * ($viaje['comision_admin_porcentaje'] / 100);
        $gananciaEmpresa = $viaje['comision_plataforma_valor'] - $comisionAdminValor;
        
        $updateViaje = $db->prepare("
            UPDATE viaje_resumen_tracking 
            SET comision_admin_porcentaje = :comision_admin_porcentaje,
                comision_admin_valor = :comision_admin_valor,
                ganancia_empresa = :ganancia_empresa
            WHERE id = :id
        ");
        $updateViaje->execute([
            ':comision_admin_porcentaje' => $viaje['comision_admin_porcentaje'],
            ':comision_admin_valor' => $comisionAdminValor,
            ':ganancia_empresa' => $gananciaEmpresa,
            ':id' => $viaje['id']
        ]);
        $viajesActualizados++;
    }
    
    echo "Viajes actualizados con comision_admin: {$viajesActualizados}\n\n";
    
    // 1. Obtener todas las empresas activas
    $empresasQuery = $db->query("SELECT id, nombre, saldo_pendiente, comision_admin_porcentaje FROM empresas_transporte WHERE estado = 'activo'");
    $empresas = $empresasQuery->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Empresas encontradas: " . count($empresas) . "\n\n";
    
    $empresasActualizadas = 0;
    $totalCargosGenerados = 0;
    
    foreach ($empresas as $empresa) {
        $empresaId = $empresa['id'];
        $nombreEmpresa = $empresa['nombre'];
        $saldoAnterior = floatval($empresa['saldo_pendiente']);
        $comisionPorcentaje = floatval($empresa['comision_admin_porcentaje']);
        
        echo "--- Empresa: {$nombreEmpresa} (ID: {$empresaId}) ---\n";
        echo "   Comisión admin: {$comisionPorcentaje}%\n";
        echo "   Saldo anterior: $" . number_format($saldoAnterior, 0, ',', '.') . "\n";
        
        // 2. Calcular total de comisiones admin de viajes finalizados
        $viajesQuery = $db->prepare("
            SELECT 
                COUNT(*) as total_viajes,
                COALESCE(SUM(vrt.comision_admin_valor), 0) as total_comision_admin
            FROM viaje_resumen_tracking vrt
            JOIN solicitudes_servicio sv ON sv.id = vrt.solicitud_id
            WHERE vrt.empresa_id = :empresa_id
              AND sv.estado = 'completada'
              AND vrt.comision_admin_valor > 0
        ");
        $viajesQuery->execute([':empresa_id' => $empresaId]);
        $viajesData = $viajesQuery->fetch(PDO::FETCH_ASSOC);
        
        $totalViajes = intval($viajesData['total_viajes']);
        $totalComisionAdmin = floatval($viajesData['total_comision_admin']);
        
        echo "   Viajes finalizados con comisión: {$totalViajes}\n";
        echo "   Total comisión admin de viajes: $" . number_format($totalComisionAdmin, 0, ',', '.') . "\n";
        
        // 3. Calcular pagos ya realizados
        $pagosQuery = $db->prepare("
            SELECT COALESCE(SUM(monto), 0) as total_pagos
            FROM pagos_empresas
            WHERE empresa_id = :empresa_id AND tipo = 'pago'
        ");
        $pagosQuery->execute([':empresa_id' => $empresaId]);
        $totalPagos = floatval($pagosQuery->fetchColumn());
        
        echo "   Total pagos realizados: $" . number_format($totalPagos, 0, ',', '.') . "\n";
        
        // 4. Calcular nuevo saldo
        $nuevoSaldo = $totalComisionAdmin - $totalPagos;
        
        echo "   Nuevo saldo calculado: $" . number_format($nuevoSaldo, 0, ',', '.') . "\n";
        
        // 5. Verificar si hay registros de cargos
        $cargosQuery = $db->prepare("
            SELECT COUNT(*) as total_cargos
            FROM pagos_empresas
            WHERE empresa_id = :empresa_id AND tipo = 'cargo'
        ");
        $cargosQuery->execute([':empresa_id' => $empresaId]);
        $totalCargosExistentes = intval($cargosQuery->fetchColumn());
        
        // 6. Si no hay cargos pero sí hay viajes, crear un cargo de migración
        if ($totalCargosExistentes == 0 && $totalComisionAdmin > 0) {
            $insertCargo = $db->prepare("
                INSERT INTO pagos_empresas (empresa_id, tipo, monto, descripcion, creado_en)
                VALUES (:empresa_id, 'cargo', :monto, :descripcion, NOW())
            ");
            $insertCargo->execute([
                ':empresa_id' => $empresaId,
                ':monto' => $totalComisionAdmin,
                ':descripcion' => "Migración: Comisión acumulada de {$totalViajes} viajes finalizados"
            ]);
            echo "   ✓ Cargo de migración creado: $" . number_format($totalComisionAdmin, 0, ',', '.') . "\n";
            $totalCargosGenerados++;
        }
        
        // 7. Actualizar saldo_pendiente de la empresa
        if ($nuevoSaldo != $saldoAnterior) {
            $updateSaldo = $db->prepare("
                UPDATE empresas_transporte 
                SET saldo_pendiente = :saldo, actualizado_en = NOW()
                WHERE id = :id
            ");
            $updateSaldo->execute([
                ':saldo' => $nuevoSaldo,
                ':id' => $empresaId
            ]);
            echo "   ✓ Saldo actualizado de $" . number_format($saldoAnterior, 0, ',', '.') . 
                 " a $" . number_format($nuevoSaldo, 0, ',', '.') . "\n";
            $empresasActualizadas++;
        } else {
            echo "   ⓘ Sin cambios en saldo\n";
        }
        
        echo "\n";
    }
    
    $db->commit();
    
    echo "=== RESUMEN DE MIGRACIÓN ===\n";
    echo "Empresas procesadas: " . count($empresas) . "\n";
    echo "Empresas actualizadas: {$empresasActualizadas}\n";
    echo "Cargos de migración creados: {$totalCargosGenerados}\n";
    echo "\n✓ Migración completada exitosamente\n";
    echo "</pre>";
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "<pre>\n";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "</pre>";
    
    http_response_code(500);
}
?>
