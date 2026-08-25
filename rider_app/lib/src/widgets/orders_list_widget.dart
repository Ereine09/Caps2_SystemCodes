import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart'; // Import AppColors
import '../screens/rider_shipment_detail_screen.dart';

class OrdersListWidget extends StatefulWidget {
  final String token;
  const OrdersListWidget({super.key, required this.token});

  @override
  State<OrdersListWidget> createState() => _OrdersListWidgetState();
}

class _OrdersListWidgetState extends State<OrdersListWidget> {
  bool _loading = true;
  bool _actionLoading = false;
  String? _error;
  List<dynamic> _assignments = [];
  String _selectedTab = 'New';

  final String _baseUrl = baseUrl;

  @override
  void initState() {
    super.initState();
    _load();
  }

  static const List<Map<String, dynamic>> _tabs = [
    { 'label': 'New', 'statuses': ['pending', 'confirmed'] },
    { 'label': 'Processing', 'statuses': ['processing', 'ready_for_pickup', 'out_for_delivery', 'to_ship', 'to_receive'] },
    { 'label': 'Delivered', 'statuses': ['completed'] },
  ];

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final api = ApiClient(baseUrl: _baseUrl);
      final res = await api.getJson<Map<String, dynamic>>(
        '/modules/rider/rider_orders_api.php',
        headers: {
          'Authorization': 'Bearer ${widget.token}',
        },
      );

      final body = res.data ?? {};
      if (body['success'] != true) {
        throw Exception(body['message'] ?? 'Failed to load orders');
      }

      final data = (body['data'] as Map?) ?? {};
      final assignments = (data['assignments'] as List?) ?? [];

      setState(() {
        _assignments = assignments;
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
      });
    } finally {
      if (mounted) {
        setState(() {
          _loading = false;
        });
      }
    }
  }

  bool _matchesSelectedTab(String? status) {
    final tab = _tabs.firstWhere((tab) => tab['label'] == _selectedTab);
    final statuses = (tab['statuses'] as List<String>);
    return status != null && statuses.contains(status.toLowerCase());
  }

  Future<void> _confirmUpdate(int orderId, String action) async {
    final title = action == 'accept' ? 'Accept order?' : 'Reject order?';
    final description = action == 'accept'
        ? 'Assign this order to you and mark it as accepted.'
        : 'Release this order back to the available pool.';

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: Text(title),
          content: Text(description),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: action == 'accept' ? Colors.green : Colors.red,
              ),
              onPressed: () => Navigator.of(context).pop(true),
              child: Text(action == 'accept' ? 'Accept' : 'Reject'),
            ),
          ],
        );
      },
    );

    if (confirmed != true) return;

    setState(() {
      _actionLoading = true;
    });

    try {
      final api = ApiClient(baseUrl: _baseUrl);
      await api.updateRiderOrder(
        orderId: orderId, 
        action: action, 
        token: widget.token,
      );
      
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Order ${action == 'accept' ? 'accepted' : 'rejected'} successfully.'),
            backgroundColor: Colors.green,
          ),
        );
      }
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString().replaceAll('Exception: ', '')),
            backgroundColor: Colors.red,
          ),
        );
      }
    } finally {
      if (mounted) {
        setState(() {
          _actionLoading = false;
        });
      }
    }
  }

  void _onAccept(Map<String, dynamic> order) {
    final orderId = int.tryParse(order['order_id']?.toString() ?? order['id']?.toString() ?? '') ?? 0;
    if (orderId > 0) _confirmUpdate(orderId, 'accept');
  }

  void _onReject(Map<String, dynamic> order) {
    final orderId = int.tryParse(order['order_id']?.toString() ?? order['id']?.toString() ?? '') ?? 0;
    if (orderId > 0) _confirmUpdate(orderId, 'reject');
  }

void _showOrderDetails(int orderId) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => RiderShipmentDetailScreen(
        orderId: orderId,
        apiClient: ApiClient(baseUrl: _baseUrl),
        token: widget.token,
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
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

    if (_assignments.isEmpty) {
      return const Center(
        child: Text('No assigned deliveries yet.', style: TextStyle(color: Colors.grey)),
      );
    }

    final visibleOrders = _assignments.where((item) {
      final status = (item as Map)['order_status']?.toString() ?? '';
      return _matchesSelectedTab(status);
    }).toList();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 12),
          child: Row(
            children: _tabs.map((tab) {
              final label = tab['label'] as String;
              final isSelected = label == _selectedTab;
              return Expanded(
                child: GestureDetector(
                  onTap: () {
                    setState(() {
                      _selectedTab = label;
                    });
                  },
                  child: Container(
                    margin: const EdgeInsets.symmetric(horizontal: 4),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    decoration: BoxDecoration(
                      color: isSelected ? Colors.white : Colors.transparent,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all( // Use Theme.of(context).primaryColor for selected border
                        color: isSelected ? Theme.of(context).primaryColor : Colors.grey.shade300,
                        width: isSelected ? 1.5 : 1,
                      ),
                      boxShadow: isSelected
                          ? [BoxShadow(color: Colors.black.withValues(alpha: 0.05), blurRadius: 10, offset: const Offset(0, 4))]
                          : null,
                    ),
                    child: Center(
                      child: Text(
                        label,
                        style: TextStyle(
                          fontWeight: FontWeight.w600, // Keep font weight
                          color: isSelected ? Theme.of(context).primaryColor : Colors.grey.shade700,
                        ),
                      ),
                    ),
                  ),
                ),
              );
            }).toList(),
          ),
        ),
        if (_actionLoading)
          const LinearProgressIndicator(minHeight: 3)
        else
          const SizedBox(height: 3),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            child: visibleOrders.isEmpty
                ? ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    children: const [
                      SizedBox(height: 100),
                      Center(child: Text('No orders for this status yet.', style: TextStyle(color: Colors.grey))),
                    ],
                  )
                : ListView.builder(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    itemCount: visibleOrders.length,
                    itemBuilder: (context, index) {
                      final order = visibleOrders[index] as Map<String, dynamic>;
                      final createdAt = DateTime.tryParse(order['created_at']?.toString() ?? '');
                      final formattedDate = createdAt != null ? DateFormat('dd MMM').format(createdAt) : '';
                      final paymentMethod = order['payment_method']?.toString() ?? 'N/A';
                      final itemsSummary = order['items_summary']?.toString() ?? 'No item summary available';
                      final total = order['total'];
                      final orderId = int.tryParse(order['order_id']?.toString() ?? order['id']?.toString() ?? '') ?? 0;
                      final orderNumber = order['order_number']?.toString() ?? 'Order #$orderId';

                      return Container(
                        margin: const EdgeInsets.symmetric(vertical: 8),
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: AppColors.cardBg, // Use AppColors.cardBg
                          borderRadius: BorderRadius.circular(20), // Keep 20
                          boxShadow: [
                            BoxShadow( // Consistent shadow
                              color: Colors.black.withValues(alpha: 0.04),
                              blurRadius: 14,
                              offset: const Offset(0, 8),
                            ),
                          ],
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Expanded(
                                  child: Text(
                                    orderNumber,
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                  ),
                                ),
                                Text(
                                  formattedDate,
                                  style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                                ),
                              ],
                            ),
                            if (order['order_status']?.toString() == 'to_ship') ...[
                              const SizedBox(height: 8),
                              const Text(
                                'Ready to Pick Up - Shop / Warehouse',
                                style: TextStyle(
                                  color: AppColors.primary,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ],
                            const SizedBox(height: 14),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      const Text('Payment Type', style: TextStyle(color: Colors.grey, fontSize: 12)),
                                      const SizedBox(height: 4),
                                      Text(paymentMethod.toUpperCase().replaceAll('_', ' '), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                                    ],
                                  ),
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    const Text('Total Amount', style: TextStyle(color: Colors.grey, fontSize: 12)),
                                    const SizedBox(height: 4),
                                    Text(
                                      total != null ? 'PHP ${double.tryParse(total.toString())?.toStringAsFixed(2) ?? total}' : 'PHP 0.00',
                                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14, color: Colors.black87),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                            const SizedBox(height: 12),
                            Text(
                              'Products: $itemsSummary',
                              style: TextStyle(color: Colors.grey.shade700, fontSize: 13, height: 1.4),
                            ),
                            const SizedBox(height: 16),
                            Row(
                              children: [
                                Expanded(
                                  child: ElevatedButton(
                                    onPressed: () => _onAccept(order),
                                    // ElevatedButton theme will apply, but override background for 'Accept'
                                    style: ElevatedButton.styleFrom(backgroundColor: Colors.green, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), padding: const EdgeInsets.symmetric(vertical: 14)),
                                    child: const Text('Accept', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.white)), // Ensure bold
                                  ),
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: ElevatedButton(
                                    onPressed: () => _onReject(order),
                                    // ElevatedButton theme will apply, but override background for 'Reject'
                                    style: ElevatedButton.styleFrom(backgroundColor: AppColors.statusDangerText, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), padding: const EdgeInsets.symmetric(vertical: 14)),
                                    child: const Text('Reject', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.white)), // Ensure bold
                                  ),
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: OutlinedButton(
                                    onPressed: () => _showOrderDetails(orderId),
                                    style: OutlinedButton.styleFrom( // Apply consistent button styling
                                      side: BorderSide(color: AppColors.textMuted.withValues(alpha: 0.5)), // Use AppColors.textMuted
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), // Keep 12
                                      padding: const EdgeInsets.symmetric(vertical: 14), // Keep padding
                                    ),
                                    child: const Text('Details', style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.w600)), // Use AppColors.textMain
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}