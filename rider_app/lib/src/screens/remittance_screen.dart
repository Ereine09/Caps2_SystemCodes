import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../constants/colors.dart';
import '../constants/api_constants.dart'; // Import the new constants file
import '../api/api_client.dart'; // Import ApiClient

/// Screen to display rider's remittance details and allow them to request remittance.
class RemittanceScreen extends StatefulWidget {
  final String token;
  const RemittanceScreen({super.key, required this.token});

  @override
  State<RemittanceScreen> createState() => _RemittanceScreenState();
}

class _RemittanceScreenState extends State<RemittanceScreen> {
  Future<Map<String, dynamic>>? _remittanceFuture;
  late ApiClient _apiClient;

  @override
  void initState() {
    super.initState();
    _apiClient = ApiClient(baseUrl: baseUrl);
    _remittanceFuture = _fetchRemittanceData();
  }

  Future<Map<String, dynamic>> _fetchRemittanceData() async {
    try {
      final response = await _apiClient.getJson(
        '/modules/rider/rider_remittance_api.php?action=get_my_balance',
        headers: {
          'Authorization': 'Bearer ${widget.token}',
        },
      );

      final data = response.data;
      if (data != null && data['success'] == true) {
        return data['data'];
      } else {
        throw Exception(data?['message'] ?? 'Failed to load remittance data.');
      }
    } catch (e) {
      // The ApiClient's interceptor will provide a user-friendly error message.
      throw Exception(e.toString());
    }
  }

  /// Submits a remittance request to the backend.
  Future<void> _submitRemittance(double amount, String reference) async {
    if (amount <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a valid amount.'), backgroundColor: Colors.orange),
      );
      return;
    }
    if (reference.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a payment reference.'), backgroundColor: Colors.orange),
      );
      return;
    }

    try {
      final response = await _apiClient.postJson(
        '/modules/rider/rider_remittance_api.php?action=request_remittance',
        headers: {
          'Authorization': 'Bearer ${widget.token}',
        },
        body: {
          'amount': amount,
          'reference_number': reference,
          'payment_method': 'bank_transfer', // Or make this selectable
        },
      );

      if (response.statusCode == 200) {
        final data = response.data;
        if (data != null && data['success'] == true) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(data['message']), backgroundColor: Colors.green),
          );
          // Refresh data after successful submission
          setState(() {
            _remittanceFuture = _fetchRemittanceData();
          });
        } else {
          throw Exception(data?['message'] ?? 'Failed to submit remittance.');
        }
      } else {
        throw Exception('Server error: ${response.statusMessage}');
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: ${e.toString()}'), backgroundColor: Colors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('My Remittance'),
        backgroundColor: AppColors.primary,
        actions: [
          // Refresh button
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () {
              setState(() {
                _remittanceFuture = _fetchRemittanceData();
              });
            },
          ),
        ],
      ),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _remittanceFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Text('Error: ${snapshot.error}', style: const TextStyle(color: Colors.red)),
              ),
            );
          }

          if (!snapshot.hasData || snapshot.data!.isEmpty) {
            return const Center(child: Text('No remittance data found.'));
          }

          final data = snapshot.data!;
          final balance = double.tryParse(data['total_unremitted_cod'].toString()) ?? 0.0;
          final orders = List<Map<String, dynamic>>.from(data['orders'] ?? []);
          final currencyFormat = NumberFormat.currency(locale: 'en_PH', symbol: 'PHP ');

          return RefreshIndicator(
            onRefresh: () async {
              setState(() {
                _remittanceFuture = _fetchRemittanceData();
              });
            },
            child: ListView(
              padding: const EdgeInsets.all(16.0),
              children: [
                Card(
                  elevation: 4,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  color: AppColors.primary,
                  child: Padding(
                    padding: const EdgeInsets.all(24.0),
                    child: Column(
                      children: [
                        const Text(
                          'Total Cash on Hand',
                          style: TextStyle(fontSize: 18, color: Colors.white70),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          currencyFormat.format(balance),
                          style: const TextStyle(fontSize: 36, fontWeight: FontWeight.bold, color: Colors.white),
                        ),
                        const SizedBox(height: 16),
                        const Text(
                          'This is the total amount from completed Cash on Delivery orders that you need to remit.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white, fontSize: 14),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 24),
                Text('Pending Orders (${orders.length})', style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 8),
                ...orders.map((order) => ListTile(
                      title: Text('Order #${order['order_number']}'),
                      trailing: Text(currencyFormat.format(double.tryParse(order['total'].toString()) ?? 0)),
                      subtitle: Text(DateFormat('MMM d, yyyy - hh:mm a').format(DateTime.parse(order['created_at']))),
                    )),
              ],
            ),
          );
        },
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _showRemittanceDialog(),
        label: const Text('Remit to Admin'),
        icon: const Icon(Icons.send),
        backgroundColor: AppColors.primary,
      ),
    );
  }

  /// Shows a dialog for the rider to input remittance details.
  void _showRemittanceDialog() {
    final amountController = TextEditingController();
    final referenceController = TextEditingController();
    final formKey = GlobalKey<FormState>();

    showDialog(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: const Text('Submit Remittance'),
          content: Form(
            key: formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextFormField(
                  controller: amountController,
                  decoration: const InputDecoration(
                    labelText: 'Amount to Remit',
                    prefixText: 'PHP ',
                    border: OutlineInputBorder(),
                  ),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  validator: (value) {
                    if (value == null || value.isEmpty) return 'Amount is required';
                    if (double.tryParse(value) == null) return 'Invalid number';
                    if (double.parse(value) <= 0) return 'Amount must be positive';
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: referenceController,
                  decoration: const InputDecoration(
                    labelText: 'Payment Reference Number',
                    border: OutlineInputBorder(),
                  ),
                  validator: (value) => value == null || value.isEmpty ? 'Reference is required' : null,
                ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel')),
            ElevatedButton(
              onPressed: () {
                if (formKey.currentState!.validate()) {
                  _submitRemittance(double.parse(amountController.text), referenceController.text);
                  Navigator.of(context).pop();
                }
              },
              child: const Text('Submit'),
            ),
          ],
        );
      },
    );
  }
}