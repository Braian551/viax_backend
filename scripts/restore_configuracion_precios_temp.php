<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/config.php';

$host = (string)env_value('DB_HOST', 'localhost');
$port = (string)env_value('DB_PORT', '5432');
$db   = (string)env_value('DB_NAME', 'viax');
$user = (string)env_value('DB_USER', 'postgres');
$pass = (string)env_value('DB_PASS', '');

$dump = '/var/www/viax/backups/viax_prod_backup_20260317_230742.dump';
if (!file_exists($dump)) {
    throw new RuntimeException('No existe dump: ' . $dump);
}

$conn = (new Database())->getConnection();
$conn->exec('TRUNCATE TABLE configuracion_precios RESTART IDENTITY CASCADE');

$cmd = sprintf(
    'PGPASSWORD=%s pg_restore -a -h %s -p %s -U %s -d %s -t public.configuracion_precios %s 2>&1',
    escapeshellarg($pass),
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    escapeshellarg($db),
    escapeshellarg($dump)
);

$output = [];
$code = 1;
exec($cmd, $output, $code);
if ($code !== 0) {
    echo json_encode(['success' => false, 'code' => $code, 'output' => $output], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

$count = (int)$conn->query('SELECT COUNT(*) FROM configuracion_precios')->fetchColumn();

echo json_encode(['success' => true, 'configuracion_precios_count' => $count], JSON_UNESCAPED_UNICODE) . PHP_EOL;
