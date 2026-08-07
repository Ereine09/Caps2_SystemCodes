import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../constants/colors.dart';
import '../state/auth_state.dart';
import '../screens/earnings_screen.dart';
import '../screens/rider_shipment_screen.dart';

/// Side navigation drawer for the rider app, mirroring the reference layout:
/// profile header, vertical feature list, and a bottom action grid.
class RiderDrawer extends StatelessWidget {
  const RiderDrawer({super.key});

  void _showComingSoon(BuildContext context, String feature) {
    Navigator.of(context).pop(); // close drawer
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('$feature — coming soon'),
        backgroundColor: AppColors.primary,
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  void _openScreen(BuildContext context, Widget screen) {
    Navigator.of(context).pop(); // close drawer
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => screen),
    );
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
              onEdit: () => _showComingSoon(context, 'Edit Profile'),
            ),
            const Divider(height: 1),
            // ---- Feature List ----
            Expanded(
              child: ListView(
                padding: const EdgeInsets.symmetric(vertical: 8),
                children: [
                  _MenuTile(icon: Icons.queue_rounded, label: 'Queue Status', onTap: () => _showComingSoon(context, 'Queue Status')),
                  _MenuTile(icon: Icons.touch_app_rounded, label: 'Check In', onTap: () => _showComingSoon(context, 'Check In')),
                  _MenuTile(icon: Icons.assessment_rounded, label: 'Drive Report', onTap: () => _showComingSoon(context, 'Drive Report')),
                  _MenuTile(icon: Icons.bar_chart_rounded, label: 'Order Statistics', onTap: () => _openScreen(context, RiderShipmentScreen(token: token))),
                  _MenuTile(icon: Icons.trending_up_rounded, label: 'Performance', onTap: () => _openScreen(context, EarningsScreen(token: token))),
                  _MenuTile(icon: Icons.swap_horiz_rounded, label: 'Parcel Transfer', onTap: () => _showComingSoon(context, 'Parcel Transfer')),
                  _MenuTile(icon: Icons.school_rounded, label: 'Learning Center', onTap: () => _showComingSoon(context, 'Learning Center')),
                  _MenuTile(icon: Icons.support_agent_rounded, label: 'Help Center', onTap: () => _showComingSoon(context, 'Help Center')),
                  _MenuTile(icon: Icons.settings_rounded, label: 'Settings', onTap: () => _showComingSoon(context, 'Settings')),
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
                      onTap: () => _showComingSoon(context, 'Ticket Center'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: _ActionTile(
                      icon: Icons.photo_camera_rounded,
                      label: 'Upload ePOD',
                      badge: '3',
                      onTap: () => _showComingSoon(context, 'Upload ePOD'),
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
