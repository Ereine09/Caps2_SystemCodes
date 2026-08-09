import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';
import '../state/auth_state.dart';

/// Settings screen — shows rider account details, vehicle info, and lets the
/// rider see/update their on-duty status and log out.
class SettingsScreen extends StatefulWidget {
  final String token;
  const SettingsScreen({super.key, required this.token});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  late final ApiClient _api;
  bool _loading = true;
  bool _toggling = false;
  String? _error;
  Map<String, dynamic> _profile = {};

  @override
  void initState() {
    super.initState();
    _api = ApiClient(baseUrl: baseUrl);
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final profile = await _api.getRiderProfile(token: widget.token);
      final status = await _api.getRiderStatus(token: widget.token);
      if (mounted) {
        setState(() {
          _profile = profile;
          if (status.containsKey('is_on_duty')) {
            _profile['is_on_duty'] = status['is_on_duty'];
          }
          _loading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e.toString().replaceAll('Exception: ', '');
          _loading = false;
        });
      }
    }
  }

  Future<void> _toggleOnDuty(bool value) async {
    setState(() => _toggling = true);
    try {
      await _api.toggleRiderStatus(isOnDuty: value, token: widget.token);
      await context.read<AuthState>().setOnDuty(value);
      if (mounted) {
        setState(() => _profile['is_on_duty'] = value);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(value ? "You're now ONLINE." : "You're now OFFLINE."),
            backgroundColor: value ? AppColors.statusDeliveredText : AppColors.textMuted,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _toggling = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Settings',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.red)),
              const SizedBox(height: 16),
              ElevatedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    final isOnDuty = _profile['is_on_duty'] == true;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Account section
        _SectionHeader('Account'),
        _buildTile(
          icon: Icons.person_outline,
          title: 'Username',
          subtitle: _profile['username']?.toString() ?? '--',
        ),
        _buildTile(
          icon: Icons.email_outlined,
          title: 'Email',
          subtitle: _profile['email']?.toString() ?? '--',
        ),
        const SizedBox(height: 16),

        // Vehicle section
        _SectionHeader('Vehicle'),
        _buildTile(
          icon: Icons.two_wheeler,
          title: 'Vehicle Type',
          subtitle: _profile['vehicle_type']?.toString() ?? 'Not set',
        ),
        _buildTile(
          icon: Icons.confirmation_number,
          title: 'Plate Number',
          subtitle: _profile['plate_number']?.toString() ?? 'Not set',
        ),
        const SizedBox(height: 16),

        // Availability
        _SectionHeader('Availability'),
        _buildSwitchTile(
          title: isOnDuty ? 'Online' : 'Offline',
          subtitle: isOnDuty ? 'Available for deliveries' : 'Not accepting deliveries now',
          value: isOnDuty,
          onChanged: _toggling ? null : (bool v) => _toggleOnDuty(v),
        ),
        const SizedBox(height: 24),

        // Logout
        OutlinedButton.icon(
          onPressed: () => context.read<AuthState>().logout(),
          icon: const Icon(Icons.logout, color: AppColors.statusDangerText),
          label: const Text('Logout', style: TextStyle(color: AppColors.statusDangerText, fontWeight: FontWeight.bold)),
          style: OutlinedButton.styleFrom(
            padding: const EdgeInsets.symmetric(vertical: 16),
            side: const BorderSide(color: AppColors.statusDangerText),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
      ],
    );
  }

  Widget _buildTile({required IconData icon, required String title, required String subtitle}) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Row(
        children: [
          Icon(icon, color: AppColors.primary, size: 22),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: TextStyle(color: AppColors.textMuted, fontSize: 12)),
                const SizedBox(height: 3),
                Text(subtitle, style: const TextStyle(color: AppColors.textMain, fontWeight: FontWeight.w600, fontSize: 14)),
              ],
            ),
          ),
        ],
      ),
    );
  }

Widget _buildSwitchTile({
    required String title,
    required String subtitle,
    required bool value,
    required ValueChanged<bool>? onChanged,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: SwitchListTile(
        title: Text(title, style: const TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold)),
        subtitle: Text(subtitle, style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
        value: value,
        activeThumbColor: AppColors.statusDeliveredText,
        activeTrackColor: AppColors.statusDelivered,
onChanged: (v) => onChanged?.call(v),
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  final String title;
  const _SectionHeader(this.title);

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
      child: Text(
        title,
        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: AppColors.textMuted, letterSpacing: 0.5),
      ),
    );
  }
}

