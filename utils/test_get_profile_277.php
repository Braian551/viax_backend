<?php
// Mock $_GET
$_GET['conductor_id'] = 277;
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../conductor/get_profile.php';
?>
