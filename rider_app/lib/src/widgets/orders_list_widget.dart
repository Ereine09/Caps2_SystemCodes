import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import '../api/api_client.dart';
import 'shipment_card.dart';

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
      return const Center(
        child: Text('No assigned deliveries yet.', style: TextStyle(color: Colors.grey)),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        itemCount: _assignments.length,
        itemBuilder: (context, index) {
          final item = _assignments[index] as Map? ?? {};
          final deliveryId = item['delivery_id'];
          final orderNumber = item['order_number'] ?? '';
          final customerName = item['customer_name'] ?? '';
          final address = item['address'] ?? '';
          final orderStatusLabel = item['order_status_label'] ?? item['order_status'] ?? '';
          final total = item['total'];

          return ShipmentCard(
            orderNumber: orderNumber.toString().isEmpty ? 'Delivery #$deliveryId' : orderNumber.toString(),
            customerName: customerName.toString().isEmpty ? 'Customer' : customerName.toString(),
            address: address.toString(),
            statusLabel: orderStatusLabel.toString(),
            totalAmount: total != null ? 'PHP $total' : '',
            onTap: () {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('Tapped delivery_id=$deliveryId (detail screen TODO)')),
              );
            },
          );
        },
      ),
    );
  }
}