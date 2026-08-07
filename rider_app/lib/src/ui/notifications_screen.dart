import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../state/auth_state.dart';
import '../state/notification_state.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final auth = context.read<AuthState>();
    final token = auth.token ?? '';

    try {
      final api = ApiClient(baseUrl: baseUrl);
      final items = await api.getNotifications(token: token, limit: 50);
      final parsed = items.map((e) => Map<String, dynamic>.from(e as Map)).toList();
      if (mounted) {
        context.read<NotificationState>().setNotifications(parsed);
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = e.toString().replaceAll('Exception: ', ''));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _markAllRead() async {
    final auth = context.read<AuthState>();
    final token = auth.token ?? '';
    if (token.isEmpty) return;

    try {
      final api = ApiClient(baseUrl: baseUrl);
      await api.markAllNotificationsRead(token);
      if (mounted) {
        context.read<NotificationState>().markAllRead();
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('All notifications marked as read'),
            backgroundColor: AppColors.statusDeliveredText,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString().replaceAll('Exception: ', '')),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final notificationState = context.watch<NotificationState>();
    final notifications = notificationState.notifications;

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Notifications',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
        actions: [
          if (notificationState.unreadCount > 0)
            TextButton.icon(
              onPressed: _markAllRead,
              icon: const Icon(Icons.done_all, size: 18),
              label: const Text('Mark all read'),
            ),
          const SizedBox(width: 8),
        ],
      ),
      body: _buildBody(context, notifications, notificationState),
    );
  }

  Widget _buildBody(
    BuildContext context,
    List<Map<String, dynamic>> notifications,
    NotificationState notificationState,
  ) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                _error!,
                style: const TextStyle(color: Colors.red, fontWeight: FontWeight.w600),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              ElevatedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    if (notifications.isEmpty) {
      return const Center(
        child: Text('No notifications yet.', style: TextStyle(color: Colors.grey)),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: notifications.length,
        itemBuilder: (context, index) {
          final n = notifications[index];
          final isRead = (n['is_read']?.toString() == '1');
          final createdAt = DateTime.tryParse(n['created_at']?.toString() ?? '');
          final formatted = createdAt != null ? DateFormat('MMM d, h:mm a').format(createdAt) : '';

          return Container(
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.cardBg,
              borderRadius: BorderRadius.circular(16),
border: Border.all(
                color: isRead ? Colors.transparent : AppColors.primary.withValues(alpha: 0.4),
                width: 1.5,
              ),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.04),
                  blurRadius: 10,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: AppColors.primarySoft,
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.notifications_active_outlined, color: AppColors.primary),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              n['title']?.toString() ?? 'Notification',
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                            ),
                          ),
                          if (!isRead)
                            Container(
                              width: 8,
                              height: 8,
                              decoration: const BoxDecoration(color: AppColors.primary, shape: BoxShape.circle),
                            ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(
                        n['message']?.toString() ?? '',
                        style: TextStyle(color: Colors.grey.shade700, fontSize: 13, height: 1.4),
                      ),
                      if (formatted.isNotEmpty) ...[
                        const SizedBox(height: 8),
                        Text(formatted, style: TextStyle(color: Colors.grey.shade500, fontSize: 11)),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
