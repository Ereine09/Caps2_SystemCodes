import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';

/// Drive Report / Queue Status / Check In screen.
///
/// Consolidates the rider's on-duty status and delivery performance stats from
/// the existing earnings + orders APIs into a single operational dashboard.
class DriveReportScreen extends StatefulWidget {
  final String token;
  const DriveReportScreen({super.key, required this.token});

  @override
  State<DriveReportScreen> createState() => _DriveReportScreenState();
}

class _DriveReportScreenState extends State<DriveReportScreen> {
  late final ApiClient _api;
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _earnings = {};
  Map<String, dynamic> _status = {};
  List<dynamic> _assignments = [];

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
      final earnings = await _api.getEarnings(token: widget.token);
      final status = await _api.getRiderStatus(token: widget.token);
      final deliveries = await _api.getDeliveries(token: widget.token);
      if (mounted) {
        setState(() {
          _earnings = earnings;
          _status = status;
          _assignments = deliveries;
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Drive Report',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
        actions: [
          IconButton(icon: const Icon(Icons.refresh, color: Colors.black), onPressed: _load),
        ],
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

    final currencyFormat = NumberFormat.currency(locale: 'en_PH', symbol: '₱');
    final summary = Map<String, dynamic>.from(_earnings['summary'] ?? {});
    final totalEarnings = double.tryParse(summary['total_earnings']?.toString() ?? '') ?? 0.0;
    final totalDeliveries = int.tryParse(summary['total_deliveries']?.toString() ?? '') ?? 0;
    final totalTips = double.tryParse(summary['total_tips']?.toString() ?? '') ?? 0.0;
    final isOnDuty = _status['is_on_duty'] == true;
    final activeCount = _assignments.where((a) {
      final s = ((a as Map)['order_status']?.toString() ?? '').toLowerCase();
      return !['completed', 'cancelled'].contains(s);
    }).length;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        children: [
          // On-duty status card
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: isOnDuty ? [AppColors.primary, const Color(0xFF006064)] : [Colors.grey.shade600, Colors.grey.shade700],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Row(
              children: [
                Icon(
                  isOnDuty ? Icons.online_prediction : Icons.offline_bolt,
                  color: Colors.white,
                  size: 40,
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        isOnDuty ? 'You are ONLINE' : 'You are OFFLINE',
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 18),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        isOnDuty ? 'Available for deliveries' : 'Tap to go online',
                        style: const TextStyle(color: Colors.white70, fontSize: 13),
                      ),
                    ],
                  ),
                ),
Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.2),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    '$activeCount active',
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // Stats grid
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            crossAxisSpacing: 10,
            mainAxisSpacing: 10,
            childAspectRatio: 1.5,
            children: [
              _buildMetricCard('Total Earnings', currencyFormat.format(totalEarnings), 'All time', AppColors.primary, Icons.payments_outlined),
              _buildMetricCard('Deliveries Done', '$totalDeliveries', 'Completed', AppColors.statusDeliveredText, Icons.local_shipping_outlined),
              _buildMetricCard('Tips Earned', currencyFormat.format(totalTips), 'From customers', AppColors.accent, Icons.volunteer_activism_outlined),
              _buildMetricCard('Active Now', '$activeCount', 'In your queue', Colors.deepPurple, Icons.queue_rounded),
            ],
          ),
          const SizedBox(height: 16),

          // Story subtitle
          const Text(
            'Your operational summary at a glance. Stats update as you complete deliveries.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppColors.textMuted, fontSize: 12),
          ),
        ],
      ),
    );
  }

  Widget _buildMetricCard(String title, String value, String subtext, Color color, IconData icon) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Row(
            children: [
              Icon(icon, size: 16, color: color),
              const SizedBox(width: 6),
              Text(title, style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            ],
          ),
          const SizedBox(height: 6),
          Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
          const SizedBox(height: 4),
          Text(subtext, style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w500)),
        ],
      ),
    );
  }
}
