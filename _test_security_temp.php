<?php
// Test temporal de seguridad — se elimina después de verificar
echo '=== TEST 1: session_key.php con user_id=1 ===' . PHP_EOL;
$ch = curl_init('http://127.0.0.1/auth/session_key.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['user_id' => 1, 'device_id' => 'test_device_001']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Device-Fingerprint: test_fp_abc123']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo 'HTTP ' . $code . ': ' . $resp . PHP_EOL . PHP_EOL;

echo '=== TEST 2: session_key.php sin body ===' . PHP_EOL;
$ch = curl_init('http://127.0.0.1/auth/session_key.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo 'HTTP ' . $code . ': ' . $resp . PHP_EOL . PHP_EOL;

echo '=== TEST 3: create_trip_request.php sin body ===' . PHP_EOL;
$ch = curl_init('http://127.0.0.1/user/create_trip_request.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo 'HTTP ' . $code . ': ' . $resp . PHP_EOL . PHP_EOL;

echo '=== TEST 4: health.php ===' . PHP_EOL;
$ch = curl_init('http://127.0.0.1/health.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo 'HTTP ' . $code . ': OK' . PHP_EOL . PHP_EOL;

echo '=== TEST 5: Redis session keys ===' . PHP_EOL;
try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $keys = $redis->keys('session:1:*');
    echo 'Session keys en Redis para user 1: ' . count($keys) . PHP_EOL;
    foreach ($keys as $key) {
        $ttl = $redis->ttl($key);
        echo '  Key: ' . $key . ' TTL: ' . $ttl . 's' . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'Redis error: ' . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . '=== TODOS LOS TESTS COMPLETADOS ===' . PHP_EOL;
