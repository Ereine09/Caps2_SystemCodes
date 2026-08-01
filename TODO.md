# Fix: Rider App Login "Network error: Unable to connect to the server"

## Root Causes Found
1. Login screen calls `/modules/auth/login_api.php?role=rider` which does **NOT** exist (404). The real endpoint is `/modules/rider/rider_login_api.php`.
2. Login screen sends `username` but `rider_login_api.php` expects `username_email`.
3. Login screen expects `data['user']` but the API returns `data['data']` with `token`, `username`, `user_id`.
4. `api_constants.dart` used `192.168.1.5` but the PC's actual IP is `192.168.1.84` (from `ipconfig`).

## Plan Steps
- [x] Investigate the files and verify server endpoints
- [x] Update `api_constants.dart`: correct IP + add `customerBaseUrl`
- [x] Fix `login_screen.dart`: endpoint, payload key, response parsing
- [x] Fix `orders_list_widget.dart`: use centralized `baseUrl`
- [x] Fix `screens/qr_scanner_screen.dart`: use centralized `baseUrl`
- [x] Fix `ui/qr_scanner_screen.dart`: use centralized `baseUrl`
- [x] Fix `ui/register_screen.dart`: use centralized `baseUrl`
- [x] Fix `screens/rider_shipment_screen.dart`: use `customerBaseUrl`
- [x] Verify with `flutter analyze`

## Result
✅ All fixes applied. The server endpoint is reachable and returns proper JSON (verified with curl). The app should now be able to login successfully.

