import 'dart:async';
import 'dart:convert';
import 'package:web_socket_channel/web_socket_channel.dart';
import '../constants/api_constants.dart';
import '../state/notification_state.dart';

/// Manages a single WebSocket connection to the Ratchet server for the
/// authenticated rider. It listens for `notification` events and updates the
/// [NotificationState] in real time.
class RealtimeService {
  final NotificationState notificationState;
  final String token;

  WebSocketChannel? _channel;
  StreamSubscription? _subscription;
  Timer? _reconnectTimer;
  bool _connecting = false;
  bool _disposed = false;

  RealtimeService({required this.notificationState, required this.token});

  /// Builds the WebSocket URL with the JWT in the query string.
  String get _wsUri => '$wsUrl/?token=${Uri.encodeComponent(token)}';

  /// Establishes the connection and listens for incoming events.
  void connect() {
    if (_connecting || _disposed || token.isEmpty) return;
    _connecting = true;

    try {
      _channel = WebSocketChannel.connect(Uri.parse(_wsUri));
      notificationState.setConnected(true);

      _subscription = _channel!.stream.listen(
        _onMessage,
        onDone: _onClosed,
        onError: (Object _) => _onClosed(),
      );
    } catch (_) {
      _connecting = false;
      _scheduleReconnect();
      return;
    }
    _connecting = false;
  }

  /// Handles an incoming WebSocket text payload.
  void _onMessage(dynamic data) {
    final raw = data?.toString() ?? '';
    if (raw.isEmpty) return;

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map<String, dynamic>) return;

      final type = decoded['type']?.toString() ?? '';
      if (type == 'notification') {
        final notification = <String, dynamic>{
          'id': int.tryParse(decoded['notification_id']?.toString() ?? '0') ?? 0,
          'title': decoded['title']?.toString() ?? 'Notification',
          'message': decoded['message']?.toString() ?? '',
          'type': decoded['reference_table']?.toString() ?? 'general',
          'created_at': decoded['created_at']?.toString() ?? '',
          'is_read': 0,
        };
        notificationState.incrementUnread();
        notificationState.addNotification(notification);
      } else if (type == 'ws_ready') {
        // Connection established by the server; nothing extra needed.
        notificationState.setConnected(true);
      }
    } catch (_) {
      // Ignore malformed messages.
    }
  }

  void _onClosed() {
    notificationState.setConnected(false);
    _subscription?.cancel();
    _subscription = null;
    _channel = null;
    _scheduleReconnect();
  }

  void _scheduleReconnect() {
    if (_disposed) return;
    _reconnectTimer?.cancel();
    _reconnectTimer = Timer(const Duration(seconds: 5), () {
      if (!_disposed) connect();
    });
  }

  /// Closes the connection and stops reconnection attempts.
  void dispose() {
    _disposed = true;
    _reconnectTimer?.cancel();
    _subscription?.cancel();
    _subscription = null;
    _channel?.sink.close();
    _channel = null;
    notificationState.setConnected(false);
  }
}
