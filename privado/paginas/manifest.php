<?php
/**
 * manifest.php — Manifest de la PWA (se sirve como application/manifest+json).
 * Los íconos llevan la versión central para que el celular los actualice al subir la versión.
 */
declare(strict_types=1);

$raiz = RAIZ_PRIVADA;

$v = '?v=' . rawurlencode(APP_VERSION);
$b = base_path();
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode([
    'name'             => 'Moscode Panel',
    'short_name'       => 'Moscode',
    'description'      => 'Panel de clientes, cobros y vencimientos',
    'lang'             => 'es-AR',
    'id'               => $b . '/',
    'start_url'        => $b . '/',
    'scope'            => $b . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'background_color' => '#141517',
    'theme_color'      => '#141517',
    'icons'            => [
        ['src' => "$b/assets/img/icon-192.png$v", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => "$b/assets/img/icon-512.png$v", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => "$b/assets/img/icon-maskable-192.png$v", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => "$b/assets/img/icon-maskable-512.png$v", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
