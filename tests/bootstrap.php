<?php

declare(strict_types=1);

/*
 * Arranque de la suite.
 *
 * Antes de que Laravel toque la base, las dos bases de pruebas tienen que
 * existir: la primera conexión ocurre dentro de `setUp()` —al abrir la
 * transacción que aísla cada prueba— y para entonces ya es tarde para crearlas.
 *
 * Se crean vacías; el esquema lo levanta `Tests\TenantTestCase`.
 */

require __DIR__.'/../vendor/autoload.php';

if (file_exists(__DIR__.'/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__.'/..')->safeLoad();
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$puerto = $_ENV['DB_PORT'] ?? '3306';
$usuario = $_ENV['DB_USERNAME'] ?? 'root';
$clave = $_ENV['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO("mysql:host={$host};port={$puerto}", $usuario, $clave);

    foreach (['acadion_testing', 'acadion_testing_central'] as $base) {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
} catch (Throwable $e) {
    // Sin MySQL a mano, las pruebas que lo necesitan fallarán con su propio
    // mensaje. No se aborta aquí: las pruebas puras no tienen por qué caerse
    // porque el servidor de base de datos esté apagado.
    fwrite(STDERR, "Aviso: no se pudieron preparar las bases de pruebas ({$e->getMessage()})\n");
}
