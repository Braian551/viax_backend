-- Corrige `reportes_usuarios.id` en esquemas legacy sin DEFAULT autoincremental.

DO $$
DECLARE
    seq_name text;
    next_value bigint;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'reportes_usuarios'
          AND column_name = 'id'
    ) THEN
        seq_name := pg_get_serial_sequence('reportes_usuarios', 'id');

        IF seq_name IS NULL THEN
            IF NOT EXISTS (
                SELECT 1
                FROM pg_class
                WHERE relkind = 'S'
                  AND relname = 'reportes_usuarios_id_seq'
            ) THEN
                CREATE SEQUENCE reportes_usuarios_id_seq;
            END IF;

            SELECT COALESCE(MAX(id), 0) + 1
            INTO next_value
            FROM reportes_usuarios;

            PERFORM setval('reportes_usuarios_id_seq', next_value, false);

            ALTER TABLE reportes_usuarios
                ALTER COLUMN id SET DEFAULT nextval('reportes_usuarios_id_seq');
        END IF;
    END IF;
END $$;
