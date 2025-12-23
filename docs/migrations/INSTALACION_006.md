# 🚀 Instalación Rápida - Sistema de Carga de Documentos

## Paso 1: Ejecutar Migración de Base de Datos

```bash
cd c:\Flutter\ping_go\pingo\backend\migrations
php run_migration_006.php
```

**Salida esperada:**
```
=== Iniciando migración 006: Documentos Conductor ===

Ejecutando declaración 1...
✓ Completada

...

=== Migración 006 completada exitosamente ===

Columnas agregadas a detalles_conductor:
  - licencia_foto_url
  - soat_foto_url
  - tecnomecanica_foto_url
  - tarjeta_propiedad_foto_url
  - seguro_foto_url

Tabla creada:
  - documentos_conductor_historial

✓ Directorio uploads creado
✓ Directorio documentos creado
✓ Archivo .htaccess creado
✓ Archivo .gitignore creado
```

---

## Paso 2: Verificar Estructura de Carpetas

```bash
cd c:\Flutter\ping_go\pingo\backend
dir uploads /s
```

**Debe existir:**
```
uploads/
├── .htaccess
├── .gitignore
└── documentos/
```

---

## Paso 3: Instalar Dependencia en Flutter

```bash
cd c:\Flutter\ping_go
flutter pub get
```

**Verifica que esté en pubspec.yaml:**
```yaml
dependencies:
  image_picker: ^1.0.7
```

---

## Paso 4: Probar el Sistema

### Opción A: Desde la App
1. Abrir la app
2. Login como conductor
3. Ir a "Registrar Vehículo"
4. Seleccionar fotos de documentos
5. Guardar

### Opción B: Test Manual con cURL
```bash
curl -X POST http://10.0.2.2/pingo/backend/conductor/upload_documents.php \
  -F "conductor_id=7" \
  -F "tipo_documento=soat" \
  -F "documento=@/path/to/image.jpg"
```

**Respuesta esperada:**
```json
{
  "success": true,
  "message": "Documento subido exitosamente",
  "data": {
    "tipo_documento": "soat",
    "url": "uploads/documentos/conductor_7/soat_1730000000_abc123.jpg",
    "conductor_id": 7,
    "fecha_subida": "2025-10-25 15:30:00"
  }
}
```

---

## ✅ Checklist

- [ ] Migración 006 ejecutada exitosamente
- [ ] Carpeta `uploads/documentos` existe
- [ ] Archivo `.htaccess` en uploads
- [ ] `image_picker` instalado en Flutter
- [ ] Permisos de escritura en `uploads` (chmod 755)
- [ ] Test de upload desde cURL funciona
- [ ] Test desde app funciona

---

## ⚠️ Problemas Comunes

### Error: "Permission denied"
```bash
chmod 755 pingo/backend/uploads
chmod 755 pingo/backend/uploads/documentos
```

### Error: "Tabla ya existe"
Si la migración falla porque las columnas ya existen, ejecutar:
```sql
-- Verificar columnas existentes
DESCRIBE detalles_conductor;

-- Si ya existen, no hacer nada
```

### Error: "image_picker not found"
```bash
flutter clean
flutter pub get
```

---

## 📊 Verificar en Base de Datos

```sql
-- Ver columnas agregadas
DESCRIBE detalles_conductor;

-- Ver historial
SELECT * FROM documentos_conductor_historial;

-- Ver documentos de un conductor
SELECT 
  licencia_foto_url,
  soat_foto_url,
  tecnomecanica_foto_url,
  tarjeta_propiedad_foto_url
FROM detalles_conductor
WHERE usuario_id = 7;
```

---

## 🎯 ¡Listo!

El sistema está completo y funcional. Los conductores ahora pueden:
- ✅ Subir fotos de SOAT
- ✅ Subir fotos de Tecnomecánica
- ✅ Subir fotos de Tarjeta de Propiedad
- ✅ Subir fotos de Licencia de Conducción

**Documentación completa:** `docs/conductor/SISTEMA_CARGA_DOCUMENTOS.md`
