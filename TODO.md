# Rider Remittance & Rider App Improvement Plan

## Backend Fixes
- [x] Fix `modules/rider/rider_complete_order_api.php` — use `order_status` column instead of `status`
- [x] Verify `rider_orders_api.php` returns `items` array for `get_order_details`

## Flutter App Fixes
- [x] Rework `api_client.dart` — add JWT-authenticated delivery methods, remove hardcoded riderId
- [x] Update `order.dart` — parse real API shape (order_id, address, items_summary)
- [x] Wire `rider_shipment_screen.dart` — read token from AuthState, no hardcoded IDs
- [x] Wire `rider_shipment_detail_screen.dart` — read token from AuthState, no hardcoded IDs
- [x] Make shipment filter chips actually filter by date, compute real metrics
- [x] Consolidate duplicate screens (removed orphaned `src/screens/qr_scanner_screen.dart` http version and `src/screens/rider_home_dashboard.dart`; unified on the Dio `src/ui/qr_scanner_screen.dart`)

## Verification
- [x] Run `php -l` on edited backend files
- [x] Run `flutter analyze` on rider_app (if available)
