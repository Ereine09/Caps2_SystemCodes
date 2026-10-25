<?php
require __DIR__ . '/../vendor/autoload.php';

use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;
use Ws\ChatServer;

$port = 8080;
$server = IoServer::factory(

    new HttpServer(
        new WsServer(new ChatServer())
    ),
    (int)$port,
    '0.0.0.0'
);

echo "WebSocket server started on port {$port}\n";
$server->run();

