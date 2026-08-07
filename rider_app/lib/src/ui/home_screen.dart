import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../state/auth_state.dart';
import '../state/notification_state.dart';
import '../widgets/orders_list_widget.dart';
import '../widgets/rider_bottom_nav.dart';
import '../constants/colors.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import 'notifications_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  bool _statusLoading = false;

@override
  void initState() {
    super.initState();
    // Sync the persisted/local on-duty status with the backend on load.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadStatus();
      _loadUnreadCount();
    });
  }

  /// Fetches the initial unread notification count for the unread badge.
  Future<void> _loadUnreadCount() async {
    final auth = context.read<AuthState>();
    final token = auth.token ?? '';
    if (token.isEmpty) return;

    try {
      final api = ApiClient(baseUrl: baseUrl);
      final count = await api.getUnreadNotificationCount(token: token);
      if (mounted) {
        context.read<NotificationState>().setUnreadCount(count);
      }
    } catch (_) {
      // Ignore network errors on initial load; badge stays hidden.
    }
  }

  /// Opens the notifications list screen.
  void _openNotifications() {
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const NotificationsScreen()),
    );
  }

  /// Fetches the current on-duty status from the backend and updates AuthState.
  Future<void> _loadStatus() async {
    final auth = context.read<AuthState>();
    final token = auth.token ?? '';
    if (token.isEmpty) return;

    try {
      final api = ApiClient(baseUrl: baseUrl);
      final data = await api.getRiderStatus(token: token);
      final isOnDuty = (data['is_on_duty'] as bool?) ?? false;
      if (mounted && isOnDuty != auth.isOnDuty) {
        await auth.setOnDuty(isOnDuty);
      }
    } catch (_) {
      // Silently ignore network errors on initial load; keep local state.
    }
  }

  /// Toggles the rider's on-duty status via the backend.
  Future<void> _toggleStatus(bool value) async {
    final auth = context.read<AuthState>();
    final token = auth.token ?? '';
    if (token.isEmpty) {
      _showMessage('Authentication token missing. Please log in again.', isError: true);
      return;
    }

    setState(() => _statusLoading = true);
    try {
      final api = ApiClient(baseUrl: baseUrl);
      await api.toggleRiderStatus(isOnDuty: value, token: token);
      await auth.setOnDuty(value);
      _showMessage(
        value ? "You're now ONLINE and available for deliveries." : "You're now OFFLINE. No new deliveries will be assigned.",
        isError: false,
      );
    } catch (e) {
      _showMessage(e.toString().replaceAll('Exception: ', ''), isError: true);
    } finally {
      if (mounted) setState(() => _statusLoading = false);
    }
  }

  void _showMessage(String message, {required bool isError}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Colors.red : AppColors.statusDeliveredText,
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

@override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final notificationState = context.watch<NotificationState>();

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        automaticallyImplyLeading: false,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Welcome Back,',
              style: TextStyle(color: AppColors.textMuted, fontSize: 12),
            ),
            Text(
              auth.username ?? 'Rider',
              style: const TextStyle(
                color: AppColors.textMain,
                fontSize: 18,
                fontWeight: FontWeight.bold,
              ),
            ),
          ],
        ),
        actions: [
          // Online/Offline toggle switch
          Container(
            margin: const EdgeInsets.only(right: 8),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(
                color: auth.isOnDuty
                    ? AppColors.statusDeliveredText
                    : AppColors.textMuted.withValues(alpha: 0.3),
                width: 1.5,
              ),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.04),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                )
              ],
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  auth.isOnDuty ? Icons.online_prediction : Icons.offline_bolt,
                  size: 18,
                  color: auth.isOnDuty ? AppColors.statusDeliveredText : AppColors.textMuted,
                ),
                const SizedBox(width: 6),
                Text(
                  auth.isOnDuty ? 'Online' : 'Offline',
                  style: TextStyle(
                    color: auth.isOnDuty ? AppColors.statusDeliveredText : AppColors.textMuted,
                    fontWeight: FontWeight.w600,
                    fontSize: 13,
                  ),
                ),
                const SizedBox(width: 4),
                _statusLoading
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Switch(
                        value: auth.isOnDuty,
                        onChanged: _toggleStatus,
                        activeThumbColor: AppColors.statusDeliveredText,
                        inactiveThumbColor: AppColors.textMuted,
                        activeTrackColor: AppColors.statusDelivered, // lighter shade
                        inactiveTrackColor: AppColors.statusTransit,
                        materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
              ],
            ),
          ),
          Container(
            margin: const EdgeInsets.only(right: 12),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.04),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                )
              ],
            ),
child: IconButton(
              icon: Stack(
                clipBehavior: Clip.none,
                children: [
                  const Icon(Icons.notifications_none_outlined, color: AppColors.textMain),
                  if (notificationState.unreadCount > 0)
                    Positioned(
                      right: -4,
                      top: -4,
                      child: Container(
                        padding: const EdgeInsets.all(4),
                        constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                        decoration: const BoxDecoration(
                          color: AppColors.statusDangerText,
                          shape: BoxShape.circle,
                        ),
                        child: Text(
                          notificationState.unreadCount > 99
                              ? '99+'
                              : '${notificationState.unreadCount}',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 9,
                            fontWeight: FontWeight.bold,
                          ),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                ],
              ),
              onPressed: _openNotifications,
            ),
          ),
        ],
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20.0, vertical: 10),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Assigned Deliveries',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppColors.textMain),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: (auth.isOnDuty ? AppColors.statusDeliveredText : AppColors.textMuted).withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    auth.isOnDuty ? 'Active Duty' : 'Off Duty',
                    style: TextStyle(
                      color: auth.isOnDuty ? AppColors.statusDeliveredText : AppColors.textMuted,
                      fontWeight: FontWeight.w600,
                      fontSize: 12,
                    ),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: OrdersListWidget(token: auth.token ?? ''),
          ),
        ],
      ),
      bottomNavigationBar: const RiderBottomNavBar(),
    );
  }
}
