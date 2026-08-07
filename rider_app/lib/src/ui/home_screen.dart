import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../state/auth_state.dart';
import '../widgets/rider_bottom_nav.dart';
import '../constants/colors.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../state/notification_state.dart';
import 'rider_delivery_dashboard.dart';

/// Home screen shell. Displays the teal Delivery Dashboard (header, drawer,
/// status tabs, parcel cards & QR FAB) and keeps the rider bottom navigation.
/// On-duty status and unread notification count are synced on load into the
/// shared AuthState / NotificationState read by the dashboard.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
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

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();

    return Scaffold(
      backgroundColor: AppColors.background,
      // The new teal Delivery Dashboard (with header, drawer, tabs, parcel
      // cards & QR FAB) replaces the previous body. The on-duty toggle and
      // notification badge are now handled inside the dashboard header.
      body: RiderDeliveryDashboard(token: auth.token ?? ''),
      bottomNavigationBar: const RiderBottomNavBar(),
    );
  }
}
