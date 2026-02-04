-- Migration: Create vehicle colors table (PostgreSQL)
-- Date: 2025-12-25

CREATE TABLE IF NOT EXISTS "colores_vehiculo" (
    "id" SERIAL PRIMARY KEY,
    "nombre" VARCHAR(50) NOT NULL,
    "hex_code" VARCHAR(7) NOT NULL,
    "activo" BOOLEAN DEFAULT TRUE
);

-- Insert initial colors
INSERT INTO "colores_vehiculo" ("nombre", "hex_code") VALUES 
('Negro', '#000000'),
('Blanco', '#FFFFFF'),
('Gris', '#808080'),
('Plateado', '#C0C0C0'),
('Azul', '#0000FF'),
('Rojo', '#FF0000'),
('Verde', '#008000'),
('Amarillo', '#FFFF00'),
('Naranja', '#FFA500'),
('Marrón', '#A52A2A'),
('Beige', '#F5F5DC'),
('Dorado', '#FFD700'),
('Otro', '#CCCCCC');

-- Add index
CREATE INDEX IF NOT EXISTS "idx_color_activo" ON "colores_vehiculo" ("activo");
