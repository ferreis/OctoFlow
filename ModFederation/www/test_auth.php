<?php
require __DIR__ . '/vendor/autoload.php';

$client = new \GuzzleHttp\Client([
    'base_uri' => 'https://localhost:4481/OctoFlow/api/',
    'verify' => false,
    'cookies' => true,
]);

echo "1. Login...\n";
$response = $client->post('auth/login', [
    'json' => ['email' => 'admin@example.com', 'password' => 'Senha@123']
]);

$data = json_decode($response->getBody(), true);
$jwt = $data['token'];
echo "JWT: " . substr($jwt, 0, 20) . "...\n";

echo "2. Fetching /auth/me...\n";
try {
    $res2 = $client->get('auth/me', [
        'headers' => ['Authorization' => 'Bearer ' . $jwt]
    ]);
    echo "Auth/Me Status: " . $res2->getStatusCode() . "\n";
} catch (\GuzzleHttp\Exception\ClientException $e) {
    echo "Auth/Me Failed: " . $e->getResponse()->getStatusCode() . " - " . (string)$e->getResponse()->getBody() . "\n";
}

echo "3. Refreshing token...\n";
$res3 = $client->post('auth/refresh');
$data3 = json_decode($res3->getBody(), true);
$newJwt = $data3['token'];
echo "New JWT: " . substr($newJwt, 0, 20) . "...\n";
