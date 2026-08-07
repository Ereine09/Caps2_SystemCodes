import 'package:flutter/foundation.dart';

class NotificationState extends ChangeNotifier {
  int _unreadCount = 0;
  List<Map<String, dynamic>> _notifications = [];
  bool _connected = false;

  int get unreadCount => _unreadCount;
  List<Map<String, dynamic>> get notifications => _notifications;
  bool get connected => _connected;

  /// Replaces the unread counter and notifies listeners.
  void setUnreadCount(int count) {
    if (_unreadCount != count) {
      _unreadCount = count;
      notifyListeners();
    }
  }

  /// Increments the unread counter by 1 (used when a real-time event arrives).
  void incrementUnread() {
    _unreadCount += 1;
    notifyListeners();
  }

  /// Replaces the full notification list.
  void setNotifications(List<Map<String, dynamic>> items) {
    _notifications = List<Map<String, dynamic>>.from(items);
    notifyListeners();
  }

  /// Prepends a single real-time notification to the list.
  void addNotification(Map<String, dynamic> item) {
    _notifications = [item, ..._notifications];
    notifyListeners();
  }

  /// Marks all notifications as read locally.
  void markAllRead() {
    _unreadCount = 0;
    _notifications = _notifications
        .map((n) => {...n, 'is_read': 1})
        .toList();
    notifyListeners();
  }

  /// Updates the WebSocket connection status.
  void setConnected(bool value) {
    if (_connected != value) {
      _connected = value;
      notifyListeners();
    }
  }
}
