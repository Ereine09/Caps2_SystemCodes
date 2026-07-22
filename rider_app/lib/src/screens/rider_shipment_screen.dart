import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api/api_client.dart';
import '../screens/order.dart'; // Create this model file
import '../widgets/shipment_card.dart';
import 'rider_shipment_detail_screen.dart'; // The new detail screen

class RiderShipmentScreen extends StatefulWidget {
  const RiderShipmentScreen({Key? key}) : super(key: key);

  @override
  State<RiderShipmentScreen> createState() => _RiderShipmentScreenState();
}

class _RiderShipmentScreenState extends State<RiderShipmentScreen> {
  late Future<List<Order>> _deliveriesFuture;
  final ApiClient _apiClient = ApiClient(baseUrl: 'http://192.168.1.8/loyalty_managements/customer'); // Replace with your actual base URL

  @override
  void initState() {
    super.initState();
    _deliveriesFuture = _fetchDeliveries();
  }

  Future<List<Order>> _fetchDeliveries() async {
    try {
      // In a real app, the riderId would be dynamic
      final List<dynamic> data = await _apiClient.getDeliveries(riderId: 1);
      return data.map((json) => Order.fromJson(json)).toList();
    } catch (e) {
      // The ApiClient's interceptor will provide a clean error message.
      throw Exception('Failed to load deliveries: $e');
    }
  }

  Future<void> _refreshDeliveries() async {
    setState(() {
      _deliveriesFuture = _fetchDeliveries();
    });
  }

  void _navigateToDetail(Order order) async {
    final result = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (context) => RiderShipmentDetailScreen(
          orderId: order.id,
          apiClient: _apiClient,
        ),
      ),
    );

    // If the detail screen returns true, it means an update happened.
    if (result == true) {
      _refreshDeliveries();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF7F8FA),
      appBar: AppBar(
        title: const Text('Assigned Shipments',
            style: TextStyle(color: Color(0xFF1E1F22), fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: Colors.white,
        elevation: 0.5,
        centerTitle: true,
      ),
      body: RefreshIndicator(
        onRefresh: _refreshDeliveries,
        child: FutureBuilder<List<Order>>(
          future: _deliveriesFuture,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(child: Text('Error: ${snapshot.error}'));
            }
            if (!snapshot.hasData || snapshot.data!.isEmpty) {
              return const Center(child: Text('No assigned deliveries found.'));
            }

            final orders = snapshot.data!;
            return ListView.builder(
              padding: const EdgeInsets.all(15),
              itemCount: orders.length,
              itemBuilder: (context, index) {
                final order = orders[index];
                final currencyFormat = NumberFormat.currency(locale: 'en_PH', symbol: 'PHP ');

                return ShipmentCard(
                  orderNumber: order.orderNumber,
                  customerName: order.customerName,
                  address: order.deliveryAddress,
                  statusLabel: order.orderStatus.replaceAll('_', ' ').toUpperCase(),
                  totalAmount: currencyFormat.format(order.total),
                  onTap: () => _navigateToDetail(order),
                );
              },
            );
          },
        ),
      ),
    );
  }
}