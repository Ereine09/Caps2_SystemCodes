import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../api/api_client.dart';
import '../screens/order.dart';
import '../state/auth_state.dart';
import '../ui/qr_scanner_screen.dart'; // Ensure this path is correct

class RiderShipmentDetailScreen extends StatefulWidget {
  final int orderId;
  final ApiClient apiClient;

  const RiderShipmentDetailScreen({
    Key? key,
    required this.orderId,
    required this.apiClient,
  }) : super(key: key);

  @override
  State<RiderShipmentDetailScreen> createState() => _RiderShipmentDetailScreenState();
}

class _RiderShipmentDetailScreenState extends State<RiderShipmentDetailScreen> {
  late Future<Order> _detailsFuture;
  bool _isVerifyingQR = false;

  @override
  void initState() {
    super.initState();
    _detailsFuture = _fetchDetails();
  }

  Future<Order> _fetchDetails() async {
    try {
      final data = await widget.apiClient.getDeliveryDetails(widget.orderId);
      return Order.fromJson(data);
    } catch (e) {
      throw Exception('Failed to load order details: $e');
    }
  }

  void _navigateToQRScanner() async {
    // In a real app, get the riderId from your authentication state management solution.
    const int riderId = 1;

    final authToken = context.read<AuthState>().token ?? '';

    final qrCodeResult = await Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (context) => QRScannerScreen(token: authToken)),
    );

    if (qrCodeResult != null && qrCodeResult.isNotEmpty) {
      setState(() {
        _isVerifyingQR = true;
      });

      try {
        final response = await widget.apiClient.verifyDeliveryQR(qrCodeResult, riderId);

        if (response['success'] == true) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(response['message']), backgroundColor: Colors.green),
          );
          // Pop back to the list screen with a 'true' result to trigger a refresh.
          Navigator.of(context).pop(true);
        } else {
          // The API returned success: false, show the specific error message.
          throw Exception(response['message']);
        }
      } catch (e) {
        // This catches both network errors from Dio and logical errors thrown above.
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: ${e.toString().replaceFirst("Exception: ", "")}'), backgroundColor: Colors.red),
        );
      } finally {
        if (mounted) {
          setState(() {
            _isVerifyingQR = false;
          });
        }
      }
    } else {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('QR scanning cancelled.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Shipment Details'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: FutureBuilder<Order>(
        future: _detailsFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text('Error: ${snapshot.error}'));
          }
          if (!snapshot.hasData) {
            return const Center(child: Text('No order details found.'));
          }

          final order = snapshot.data!;
          final currencyFormat = NumberFormat.currency(locale: 'en_PH', symbol: 'PHP ');

          return Column(
            children: [
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.all(16.0),
                  children: [
                    _buildDetailCard('Order Info', [
                      _buildDetailRow('Order #:', order.orderNumber),
                      _buildDetailRow('Status:', order.orderStatus.replaceAll('_', ' ').toUpperCase()),
                      _buildDetailRow('Total Amount:', currencyFormat.format(order.total)),
                      _buildDetailRow('Payment Method:', order.paymentMethod.toUpperCase()),
                    ]),
                    const SizedBox(height: 16),
                    _buildDetailCard('Customer & Delivery', [
                      _buildDetailRow('Customer:', order.customerName),
                      _buildDetailRow('Contact:', order.customerPhone ?? 'N/A'),
                      _buildDetailRow('Address:', order.deliveryAddress, isAddress: true),
                    ]),
                    const SizedBox(height: 16),
                    _buildDetailCard('Order Items', [
                      for (var item in order.items)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 8.0),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Expanded(child: Text('${item.productName} (x${item.quantity})')),
                              Text(currencyFormat.format(item.totalPrice)),
                            ],
                          ),
                        ),
                      if (order.items.isEmpty) const Text('No items found for this order.'),
                    ]),
                  ],
                ),
              ),
              // --- Action Button Area ---
              if (order.orderStatus == 'out_for_delivery')
                _buildConfirmButton(),
            ],
          );
        },
      ),
    );
  }

  Widget _buildConfirmButton() {
    return Padding(
      padding: const EdgeInsets.all(16.0),
      child: SizedBox(
        width: double.infinity,
        child: ElevatedButton.icon(
          icon: _isVerifyingQR
              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.0))
              : const Icon(Icons.qr_code_scanner),
          label: Text(_isVerifyingQR ? 'Verifying...' : 'Confirm with QR Code'),
          onPressed: _isVerifyingQR ? null : _navigateToQRScanner,
          style: ElevatedButton.styleFrom(
            backgroundColor: Theme.of(context).primaryColor,
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
          ),
        ),
      ),
    );
  }

  Widget _buildDetailCard(String title, List<Widget> children) {
    return Card(
      elevation: 2,
      shadowColor: Colors.black12,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const Divider(height: 20),
            ...children,
          ],
        ),
      ),
    );
  }

  Widget _buildDetailRow(String label, String value, {bool isAddress = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4.0),
      child: Row(
        crossAxisAlignment: isAddress ? CrossAxisAlignment.start : CrossAxisAlignment.center,
        children: [
          SizedBox(
            width: 120,
            child: Text(label, style: const TextStyle(color: Colors.black54)),
          ),
          Expanded(
            child: Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }
}

