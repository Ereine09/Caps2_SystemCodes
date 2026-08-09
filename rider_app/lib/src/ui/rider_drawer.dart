import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../constants/colors.dart';
import '../state/auth_state.dart';
import '../screens/earnings_screen.dart';
import '../screens/rider_shipment_screen.dart';
import '../screens/profile_screen.dart';
import '../screens/settings_screen.dart';
import '../screens/epod_upload_screen.dart';
import '../screens/drive_report_screen.dart';
import '../screens/conversations_screen.dart';
import '../screens/parcel_transfer_screen.dart';
import '../screens/learning_center_screen.dart';
import '../screens/help_center_screen.dart';
import '../screens/ticket_center_screen.dart';

/// Side navigation drawer for the rider app, mirroring the reference layout:
/// profile header, vertical feature list, and a bottom action grid.
class RiderDrawer extends StatelessWidget {
  /// Reference to the dashboard's Scaffold so we can reliably reopen the
  /// drawer after a pushed screen is popped, instead of relying on
  /// `Scaffold.of(context)` (which can be stale once the route is removed).
  final GlobalKey<ScaffoldState>? scaffoldKey;

  const RiderDrawer({super.key, this.scaffoldKey});

  void _openScreen(BuildContext context, Widget screen) {
    Navigator.of(context).pop(); // close drawer
    Navigator.of(context)
        .push(MaterialPageRoute(builder: (_) => screen))
        .then((_) {
      // When the user navigates back from the opened screen, reopen the
      // drawer so they can pick another menu item instead of landing on the
      // dashboard. We use the dashboard's scaffold key for reliability.
      final scaffold = scaffoldKey?.currentState;
      if (scaffold != null && !scaffold.isDrawerOpen) {
        scaffold.openDrawer();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final token = auth.token ?? '';

    return Drawer(
      backgroundColor: Colors.white,
      width: 300,
      child: SafeArea(
        child: Column(
          children: [
            // ---- Profile Header ----
            _ProfileHeader(
              name: auth.username ?? 'Rider',
              rating: '4.9 ★',
              driverId: auth.userId != null ? 'Driver ID: #${auth.userId}' : 'Driver ID: --',
              onEdit: () => _openScreen(context, RiderProfileScreen(token: token)),
            ),
            const Divider(height: 1),
            // ---- Feature List ----
            Expanded(
              child: ListView(
                padding: const EdgeInsets.symmetric(vertical: 8),
                children: [
_MenuTile(icon: Icons.queue_rounded, label: 'Queue Status', onTap: () => _openScreen(context, DriveReportScreen(token: token))),
                  _MenuTile(icon: Icons.touch_app_rounded, label: 'Check In', onTap: () => _openScreen(context, DriveReportScreen(token: token))),
                  _MenuTile(icon: Icons.assessment_rounded, label: 'Drive Report', onTap: () => _openScreen(context, DriveReportScreen(token: token))),
                  _MenuTile(icon: Icons.bar_chart_rounded, label: 'Order Statistics', onTap: () => _openScreen(context, RiderShipmentScreen(token: token))),
_MenuTile(icon: Icons.trending_up_rounded, label: 'Performance', onTap: () => _openScreen(context, EarningsScreen(token: token))),
                  _MenuTile(icon: Icons.chat_bubble_outline_rounded, label: 'Messages', onTap: () => _openScreen(context, ConversationsScreen(token: token))),
                  _MenuTile(icon: Icons.swap_horiz_rounded, label: 'Parcel Transfer', onTap: () => _openScreen(context, ParcelTransferScreen(token: token))),
                  _MenuTile(icon: Icons.school_rounded, label: 'Learning Center', onTap: () => _openScreen(context, const LearningCenterScreen())),
                  _MenuTile(icon: Icons.support_agent_rounded, label: 'Help Center', onTap: () => _openScreen(context, const HelpCenterScreen())),
                  _MenuTile(icon: Icons.settings_rounded, label: 'Settings', onTap: () => _openScreen(context, SettingsScreen(token: token))),
                ],
              ),
            ),
            const Divider(height: 1),
            // ---- Bottom Action Grid ----
            Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Expanded(
child: _ActionTile(
                      icon: Icons.confirmation_number_rounded,
                      label: 'Ticket Center',
                      onTap: () => _openScreen(context, const TicketCenterScreen()),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
child: _ActionTile(
                      icon: Icons.photo_camera_rounded,
                      label: 'Upload ePOD',
                      badge: '3',
                      onTap: () => _openScreen(context, EpodUploadScreen(token: token)),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ProfileHeader extends StatelessWidget {
  final String name;
  final String rating;
  final String driverId;
  final VoidCallback onEdit;

  const _ProfileHeader({
    required this.name,
    required this.rating,
    required this.driverId,
    required this.onEdit,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: AppColors.primary,
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 20),
      child: Row(
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
              border: Border.all(color: Colors.white, width: 2),
            ),
            child: const Icon(Icons.person, color: AppColors.primary, size: 32),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  rating,
                  style: const TextStyle(color: Colors.white70, fontSize: 13),
                ),
                Text(
                  driverId,
                  style: const TextStyle(color: Colors.white70, fontSize: 12),
                ),
              ],
            ),
          ),
          IconButton(
            icon: const Icon(Icons.edit, color: Colors.white),
            onPressed: onEdit,
          ),
        ],
      ),
    );
  }
}

class _MenuTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  const _MenuTile({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(icon, color: AppColors.textMain),
      title: Text(label, style: const TextStyle(color: AppColors.textMain, fontSize: 14.5)),
      trailing: const Icon(Icons.chevron_right, color: AppColors.textMuted),
      onTap: onTap,
    );
  }
}

class _ActionTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final String? badge;
  final VoidCallback onTap;

  const _ActionTile({
    required this.icon,
    required this.label,
    required this.onTap,
    this.badge,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 14),
        decoration: BoxDecoration(
          color: AppColors.primarySoftBg,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppColors.primary.withValues(alpha: 0.2)),
        ),
        child: Column(
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                Icon(icon, color: AppColors.primary, size: 24),
                if (badge != null)
                  Positioned(
                    right: -8,
                    top: -8,
                    child: Container(
                      padding: const EdgeInsets.all(4),
                      constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                      decoration: const BoxDecoration(
                        color: AppColors.statusDangerText,
                        shape: BoxShape.circle,
                      ),
                      child: Text(
                        badge!,
                        style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold),
                        textAlign: TextAlign.center,
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              label,
              style: const TextStyle(
                color: AppColors.textMain,
                fontSize: 12,
                fontWeight: FontWeight.w600,
              ),
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}
