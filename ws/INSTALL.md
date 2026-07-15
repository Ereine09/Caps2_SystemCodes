# Ratchet WebSocket Messenger (XAMPP)

## 1) Install composer dependencies
From project root:
- `cd c:/xampp/htdocs/loyalty_managements`
- `C:\xampp\php\php.exe -r "copy('https://getcomposer.org/installer','composer-setup.php');"`
- `C:\xampp\php\php.exe composer-setup.php`
- `php composer.phar install`

Then:
- `C:\xampp\php\php.exe -d detect_unicode=0 composer.phar install`

If composer is already installed:
- `composer install`

## 2) Start WebSocket server
- `C:\xampp\php\php.exe ws/run_ws_server.php`

Server: `ws://localhost:8080`

## 3) Frontend integration
Set WS URL in both admin and customer chat pages and send JWT token in query string:
- `ws://localhost:8080/?token=YOUR_JWT`

## 4) MySQL schema
This code currently forwards messages without persistence. To persist, call your existing REST endpoints (messaging_api.php) and then broadcast.

Add tables as needed:
- typing sessions
- presence
- delivery/read receipts

