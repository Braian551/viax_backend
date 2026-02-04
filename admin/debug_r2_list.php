<?php
require_once __DIR__ . '/../config/R2Service.php';

$r2 = new R2Service();
echo "--- RAW LIST TEST ---\n";
$raw = $r2->debugList("documents/");
echo $raw;
?>
