<?php

require_once __DIR__ . '/../services/GoogleWebIdentity.php';

use Firebase\JWT\JWT;

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$rsa = openssl_pkey_get_details($key)['rsa'];
$set = ['keys' => [[
    'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'fixture',
    'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e']),
]]];
$expected = [
    'iss' => 'https://accounts.google.com', 'aud' => 'web-client', 'azp' => 'web-client',
    'exp' => time() + 600, 'sub' => '12345', 'email_verified' => true,
    'email' => 'fixture@example.com', 'nonce' => str_repeat('a', 64),
];

$valid = JWT::encode($expected, $key, 'RS256', 'fixture');
if (GoogleWebIdentity::verifyWithKeys($valid, 'web-client', $set)['sub'] !== '12345') {
    throw new RuntimeException('Valid fixture verification failed.');
}

$rejections = [
    'audience' => ['aud' => 'different-client'],
    'authorized party' => ['azp' => 'different-client'],
    'issuer' => ['iss' => 'https://example.invalid'],
    'expiry' => ['exp' => time() - 600],
    'subject' => ['sub' => ''],
    'unverified email' => ['email_verified' => false],
    'nonce' => ['nonce' => null],
];
foreach ($rejections as $scenario => $override) {
    try {
        $token = JWT::encode(array_replace($expected, $override), $key, 'RS256', 'fixture');
        GoogleWebIdentity::verifyWithKeys($token, 'web-client', $set);
    } catch (UnexpectedValueException | DomainException $error) {
        echo "Rejected {$scenario}\n";
        continue;
    }
    throw new RuntimeException("Accepted invalid {$scenario}");
}

$untrustedKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
try {
    GoogleWebIdentity::verifyWithKeys(JWT::encode($expected, $untrustedKey, 'RS256', 'fixture'), 'web-client', $set);
} catch (UnexpectedValueException | DomainException $error) {
    echo "Rejected an untrusted signature. Google identity checks passed.\n";
    exit(0);
}
throw new RuntimeException('Accepted an untrusted signature.');
