import 'package:flutter/foundation.dart'; // <-- Added for kIsWeb
import 'package:flutter/material.dart';

import '../api/api_client.dart';

class OrdersListWidget extends StatefulWidget {
  final String token;

  const OrdersListWidget({super.key, required this.token});

  @override
  State<OrdersListWidget> createState() => _OrdersListWidgetState();
}

class _OrdersListWidgetState extends State<OrdersListWidget> {
  bool _loading = true;
  String? _error;
  List<dynamic> _assignments = [];

  // Automatically switch between Localhost (for Web/iOS) and 10.0.2.2 (for Android Emulator)
  final String _baseUrl = kIsWeb
      ? 'http://localhost/loyalty_managements'
      : 'http://10.0.2.2/loyalty_managements';

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
        throw Exception(body['message'] ?? 'Failed to load assignments');
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

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(
            _error!,
            style: const TextStyle(color: Colors.red, fontWeight: FontWeight.w600),
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    if (_assignments.isEmpty) {
      return const Center(child: Text('No assigned deliveries yet.'));
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.all(12),
        itemCount: _assignments.length,
        separatorBuilder: (_, __) => const Divider(height: 1),
        itemBuilder: (context, index) {
          final item = _assignments[index] as Map? ?? {};
          final deliveryId = item['delivery_id'];
          final orderNumber = item['order_number'] ?? '';
          final customerName = item['customer_name'] ?? '';
          final address = item['address'] ?? '';

          // We want to display the real order status coming from tbl_orders
          final orderStatusLabel =
              item['order_status_label'] ?? item['order_status'] ?? '';

          final total = item['total'];

          return ListTile(
            leading: const Icon(Icons.local_shipping),
            title: Text(
              orderNumber.toString().isEmpty
                  ? 'Delivery #$deliveryId'
                  : orderNumber.toString(),
            ),
            subtitle: Text(
              '${customerName.toString().isEmpty ? 'Customer' : customerName} • $orderStatusLabel\n${address.toString().isEmpty ? '' : address}',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            trailing: Text(total != null ? 'PHP ${total.toString()}' : ''),
            isThreeLine: true,
            onTap: () {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text(
                    'Tapped delivery_id=$deliveryId (detail screen TODO)',
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

