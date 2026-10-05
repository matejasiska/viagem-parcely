<?php

declare(strict_types=1);

use Katastr\ApiController;
use Katastr\Database;
use Katastr\DlazdiceCache;
use Katastr\DlazdiceController;
use Katastr\DlazdiceRepository;
use Katastr\OkresRepository;
use Katastr\ParcelaRepository;
use Katastr\Response;
use Katastr\Router;

// Bez Composeru, proto explicitní require. Je jich málo a je vidět, co aplikace používá.
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Router.php';
require __DIR__ . '/../src/Response.php';
require __DIR__ . '/../src/DlazdiceCache.php';
require __DIR__ . '/../src/DlazdiceRepository.php';
require __DIR__ . '/../src/ParcelaRepository.php';
require __DIR__ . '/../src/OkresRepository.php';
require __DIR__ . '/../src/DlazdiceController.php';
require __DIR__ . '/../src/ApiController.php';

try {
    $db = new Database(
        (string) getenv('DB_DSN'),
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASSWORD'),
    );

    $api = new ApiController(
        new ParcelaRepository($db),
        new OkresRepository($db),
    );
    $dlazdice = new DlazdiceController(
        new DlazdiceRepository($db),
        new DlazdiceCache(__DIR__ . '/../cache'),
    );

    $router = new Router();
    $router->get('/api/okres', $api->okres(...));
    $router->get('/api/parcela/(?<id>\d+)', $api->parcela(...));
    $router->get('/api/parcely', $api->hledani(...));
    $router->get('/dlazdice/parcely/(?<z>\d+)/(?<x>\d+)/(?<y>\d+)\.pbf', $dlazdice->parcely(...));
    $router->get('/dlazdice/katastralni-uzemi/(?<z>\d+)/(?<x>\d+)/(?<y>\d+)\.pbf', $dlazdice->katastralniUzemi(...));

    $router->dispatch(
        $_SERVER['REQUEST_METHOD'],
        (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH),
        $_GET,
    );
} catch (Throwable $e) {
    // Klient nemá vidět detaily databáze, do logu patří.
    error_log(sprintf('%s: %s', $e::class, $e->getMessage()));
    Response::error(500, 'Chyba na serveru.');
}
