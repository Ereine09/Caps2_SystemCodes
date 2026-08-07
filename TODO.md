# Feature Tasks

## Feature 1 — Go Online/Offline Toggle
- [x] (Already exists) `rider_status_api.php` - GET/POST toggle status
- [x] Add `getRiderStatus()` and `toggleRiderStatus()` to `ApiClient`
- [x] Add persisted `isOnDuty` field to `AuthState`
- [x] Convert `HomeScreen` to StatefulWidget with AppBar toggle switch
- [x] `flutter analyze` - no errors

## Feature 2 — Real-time Notifications (reuse WS infra)
### Backend
- [x] Add `push_notification` handler to `ws/ChatServer.php`
- [x] Create `app/helpers/ws_push_helper.php` (PHP WebSocket client)
- [x] Call WS push from `notifications_create()` in `notification_helper.php`
- [x] Add WS_HOST/WS_PORT/WS_SERVER_URL constants to `config.php`
### Flutter
- [x] Add `wsUrl` constant to `api_constants.dart`
- [x] Create `state/notification_state.dart` (ChangeNotifier)
- [x] Create `services/realtime_service.dart` (WebSocketChannel wrapper)
- [x] Wire NotificationState + RealtimeService into `main.dart`
- [x] Add unread badge to HomeScreen notification bell
- [x] Create `ui/notifications_screen.dart`
### Verification
- [x] Run `flutter pub get` and `flutter analyze` - no errors
