import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';
import '../state/auth_state.dart';

/// Parcel Transfer screen.
///
/// Lists the rider's active (in-progress) deliveries and lets them mark a
/// parcel as "transferred" to another rider/partner. Since there is no backend
/// for transfers yet, the transfer state is tracked in-session only, but the
/// list itself is loaded from the real `rider_orders_api.php` deliveries.
class ParcelTransferScreen extends StatefulWidget {
  final String token;
  const ParcelTransferScreen({super.key, required this.token});

  @override
  State<ParcelTransferScreen> createState() => _ParcelTransferScreenState();
}

class _ParcelTransferScreenState extends State<ParcelTransferScreen> {
  late final ApiClient _api;
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _orders = [];
  final Set<String> _transferred = {};

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
      final deliveries = await _api.getDeliveries(token: widget.token);
      // Only active, transferable orders (not completed/cancelled).
      final active = deliveries.where((o) {
        final s = ((o as Map)['order_status']?.toString() ?? '').toLowerCase();
        return !['completed', 'cancelled'].contains(s);
      }).toList();
      if (mounted) {
        setState(() {
          _orders = active.map((e) => Map<String, dynamic>.from(e as Map)).toList();
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

  void _confirmTransfer(Map<String, dynamic> order) {
    final orderNo = order['order_number']?.toString() ?? 'this order';
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Transfer Parcel'),
        content: Text(
          'Transfer order #$orderNo to another rider?\n\nNote: transfers are tracked in this session only until a backend is available.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () {
              setState(() => _transferred.add(order['order_number']?.toString() ?? ''));
              Navigator.of(context).pop();
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(
                  content: Text('Parcel marked as transferred.'),
                  backgroundColor: AppColors.statusDeliveredText,
                ),
              );
            },
            child: const Text('Transfer'),
          ),
        ],
      ),
    );
  }

  Future<void> _goOnline() async {
    final auth = context.read<AuthState>();
    try {
      await _api.toggleRiderStatus(isOnDuty: true, token: widget.token);
      await auth.setOnDuty(true);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text("You're now ONLINE."), backgroundColor: AppColors.statusDeliveredText),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: Colors.red),
        );
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
          'Parcel Transfer',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
        actions: [IconButton(icon: const Icon(Icons.refresh, color: Colors.black), onPressed: _load)],
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

    // Empty/offline state hint.
    if (_orders.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.swap_horiz_rounded, size: 56, color: AppColors.textMuted),
              const SizedBox(height: 12),
              const Text(
                'No active parcels to transfer.\nWhen you have active deliveries, you can transfer them to another rider here.',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppColors.textMuted),
              ),
              const SizedBox(height: 16),
              ElevatedButton.icon(
                onPressed: _goOnline,
                icon: const Icon(Icons.online_prediction),
                label: const Text('Go Online'),
              ),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _orders.length,
        itemBuilder: (context, index) {
          final order = _orders[index];
          final orderNo = order['order_number']?.toString() ?? 'Order';
          final isTransferred = _transferred.contains(orderNo);
          final status = order['order_status_label']?.toString() ?? order['order_status']?.toString() ?? '';

          return Container(
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.cardBg,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: isTransferred ? AppColors.statusDeliveredText : Colors.grey.shade200),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.inventory_2_outlined, color: AppColors.primary, size: 20),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        orderNo,
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: isTransferred ? AppColors.statusDelivered : AppColors.primarySoftBg,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        isTransferred ? 'Transferred' : status,
                        style: TextStyle(
                          color: isTransferred ? AppColors.statusDeliveredText : AppColors.primary,
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  order['customer_name']?.toString() ?? 'Unknown',
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
                ),
                Text(
                  order['address']?.toString() ?? '',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
                ),
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: isTransferred ? null : () => _confirmTransfer(order),
                    icon: const Icon(Icons.swap_horiz, size: 18),
                    label: Text(isTransferred ? 'Transferred' : 'Transfer Parcel'),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      side: BorderSide(
                        color: isTransferred ? AppColors.textMuted : AppColors.primary,
                      ),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      foregroundColor: isTransferred ? AppColors.textMuted : AppColors.primary,
                    ),
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
