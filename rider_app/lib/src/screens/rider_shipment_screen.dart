import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart'; // Import AppColors
import '../screens/order.dart';
import 'rider_shipment_detail_screen.dart';

class RiderShipmentScreen extends StatefulWidget {
  final String token;
  const RiderShipmentScreen({Key? key, this.token = ''}) : super(key: key);

  @override
  State<RiderShipmentScreen> createState() => _RiderShipmentScreenState();
}

class _RiderShipmentScreenState extends State<RiderShipmentScreen> {
  late Future<List<Order>> _deliveriesFuture;
  final ApiClient _apiClient = ApiClient(
    baseUrl: baseUrl,
  );
  String _selectedFilter = 'Today';
  String _searchQuery = '';
  final TextEditingController _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _deliveriesFuture = _fetchDeliveries();
  }

  Future<List<Order>> _fetchDeliveries() async {
    try {
      final List<dynamic> data = await _apiClient.getDeliveries(token: widget.token);
      return data.map((json) => Order.fromJson(Map<String, dynamic>.from(json))).toList();
    } catch (e) {
      throw Exception('Failed to load deliveries: $e');
    }
  }

  Future<void> _refreshDeliveries() async {
    setState(() {
      _deliveriesFuture = _fetchDeliveries();
    });
  }

  Future<void> _updateOrderStatus(int orderId, String newStatus) async {
    try {
      final action = newStatus.toLowerCase();
      await _apiClient.updateRiderOrder(
        orderId: orderId,
        action: action,
        token: widget.token,
      );
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Order #$orderId updated to $newStatus'),
          backgroundColor: newStatus == 'Accepted' ? Colors.green : Colors.redAccent,
        ),
      );
      _refreshDeliveries();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Failed to update order: $e'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  void _navigateToDetail(Order order) async {
    final result = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (context) => RiderShipmentDetailScreen(
          orderId: order.id,
          apiClient: _apiClient,
          token: widget.token,
        ),
      ),
    );
    if (result == true) {
      _refreshDeliveries();
    }
  }

  /// Applies the selected date-window filter to the list of orders.
  List<Order> _applyFilter(List<Order> orders) {
    if (_selectedFilter == 'Today') {
      final now = DateTime.now();
      return orders.where((o) {
        final d = o.createdAt;
        return d != null && d.year == now.year && d.month == now.month && d.day == now.day;
      }).toList();
    } else if (_selectedFilter == '7 days') {
      final cutoff = DateTime.now().subtract(const Duration(days: 7));
      return orders.where((o) => o.createdAt != null && o.createdAt!.isAfter(cutoff)).toList();
    } else if (_selectedFilter == '30 days') {
      final cutoff = DateTime.now().subtract(const Duration(days: 30));
      return orders.where((o) => o.createdAt != null && o.createdAt!.isAfter(cutoff)).toList();
    }
    return orders;
  }

  @override
  Widget build(BuildContext context) {
    final currencyFormat = NumberFormat.currency(locale: 'en_PH', symbol: '₱');
    return Scaffold(
      backgroundColor: AppColors.background, // Use AppColors.background
      appBar: AppBar(
        elevation: 0,
        backgroundColor: Colors.transparent,
        title: const Text(
          'Orders Overview',
          style: TextStyle(color: Colors.black, fontWeight: FontWeight.bold, fontSize: 22),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications_none, color: Colors.black),
            onPressed: () {},
          ),
          const Padding(
            padding: EdgeInsets.only(right: 16.0),
            child: CircleAvatar(
              radius: 16,
              backgroundColor: Color(0xFF1E293B),
            ),
          ),
        ],
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
            final orders = snapshot.data ?? [];
            final dateFiltered = _applyFilter(orders);
            final filteredOrders = dateFiltered.where((o) {
              return o.orderNumber.toLowerCase().contains(_searchQuery.toLowerCase()) ||
                  o.customerName.toLowerCase().contains(_searchQuery.toLowerCase());
            }).toList();

            double totalValue = orders.fold(0, (sum, item) => sum + item.total);
            int pendingCount = orders.where((o) => o.orderStatus.toLowerCase() == 'pending').length;

            return SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    decoration: BoxDecoration( // Apply consistent card styling
                      color: AppColors.cardBg,
                      borderRadius: BorderRadius.circular(12), // Changed from 14 to 12
                      boxShadow: [
                        BoxShadow( // Consistent shadow
                          color: Colors.black.withOpacity(0.03),
                          blurRadius: 10,
                          offset: const Offset(0, 4),
                        )
                      ],
                    ),
                    child: TextField(
                      controller: _searchController,
                      onChanged: (val) => setState(() => _searchQuery = val),
                      decoration: const InputDecoration(
                        hintText: 'Search order number or customer',
                        prefixIcon: Icon(Icons.search, color: AppColors.textMuted), // Use AppColors.textMuted
                        border: InputBorder.none,
                        contentPadding: EdgeInsets.symmetric(vertical: 14),
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Row(
                    children: ['Today', '7 days', '30 days'].map((filter) {
                      bool isSelected = _selectedFilter == filter;
                      return GestureDetector(
                        onTap: () => setState(() => _selectedFilter = filter),
                        child: Container(
                          margin: const EdgeInsets.only(right: 8),
                          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8), // Keep padding
                          decoration: BoxDecoration(
                            color: isSelected ? AppColors.primary : AppColors.cardBg, // Use AppColors.primary and AppColors.cardBg
                            borderRadius: BorderRadius.circular(20),
                            border: Border.all(color: isSelected ? AppColors.primary : Colors.grey.shade300), // Border color for selected
                          ),
                          child: Text(
                            filter,
                            style: TextStyle(
                              color: isSelected ? Colors.white : Colors.black87,
                              fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                              fontSize: 13,
                            ),
                          ),
                        ),
                      );
                    }).toList(),
                  ),
                  const SizedBox(height: 16),
                  GridView.count(
                    crossAxisCount: 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    crossAxisSpacing: 10,
                    mainAxisSpacing: 10,
                    childAspectRatio: 1.5,
                    children: [ // Metric cards use REAL computed values
                      _buildMetricCard('Orders', '${orders.length}', '${pendingCount} pending', Colors.red),
                      _buildMetricCard('Delivered', '${orders.where((o) => o.orderStatus.toLowerCase() == 'completed').length}', 'Completed', Colors.green),
                      _buildMetricCard('Avg. Order Value', currencyFormat.format(orders.isEmpty ? 0 : totalValue / orders.length), 'From $totalValue total', Colors.teal),
                      _buildMetricCard('Total Order Value', currencyFormat.format(totalValue), 'Across all orders', Colors.green),
                    ],
                  ),
                  const SizedBox(height: 20),
                  Row(
                    children: [
                      const Text('Total order', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade300,
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Text('${filteredOrders.length}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  filteredOrders.isEmpty
                      ? const Padding(
                          padding: EdgeInsets.symmetric(vertical: 30),
                          child: Center(child: Text('No orders found.')),
                        )
                      : ListView.builder(
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          itemCount: filteredOrders.length,
                          itemBuilder: (context, index) {
                            final order = filteredOrders[index];
                            return _buildOrderCard(order, currencyFormat);
                          },
                        ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _buildMetricCard(String title, String value, String subtext, Color subColor) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration( // Apply consistent card styling
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(12), // Changed from 14 to 12
        border: Border.all(color: Colors.grey.shade200), // Keep border for subtle effect
        boxShadow: [ // Consistent shadow
          BoxShadow(
            color: Colors.black.withOpacity(0.02),
            blurRadius: 6,
            offset: const Offset(0, 2),
          )
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(title, style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
          const SizedBox(height: 4),
          Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
          const SizedBox(height: 4),
          Text(subtext, style: TextStyle(color: subColor, fontSize: 10, fontWeight: FontWeight.w500)),
        ],
      ),
    );
  }

  Widget _buildOrderCard(Order order, NumberFormat currencyFormat) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration( // Apply consistent card styling
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.grey.shade200), // Keep border for subtle effect
        boxShadow: [ // Consistent shadow
          BoxShadow(
            color: Colors.black.withOpacity(0.02),
            blurRadius: 8,
            offset: const Offset(0, 4),
          )
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Order #${order.orderNumber}',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
              ),
              Text(
                currencyFormat.format(order.total),
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Color(0xFF00838F)),
              ), // This color is already AppColors.primary, so no change needed.
            ],
          ),
          const SizedBox(height: 6),
          Text("Customer: ${order.customerName}", style: TextStyle(color: Colors.grey.shade700, fontSize: 13)),
          const SizedBox(height: 4),
          Text(
            'Address: ${order.deliveryAddress}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: ElevatedButton(
                  onPressed: () => _updateOrderStatus(order.id, 'Accepted'),
                  // ElevatedButton theme will apply, but we need to override background for 'Accept'
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.green, elevation: 0, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))), // Changed to 12
                  child: const Text('Accept', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)), // Ensure bold
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: ElevatedButton(
                  onPressed: () => _updateOrderStatus(order.id, 'Rejected'),
                  // ElevatedButton theme will apply, but we need to override background for 'Reject'
                  style: ElevatedButton.styleFrom(backgroundColor: AppColors.statusDangerText, elevation: 0, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))), // Changed to 12
                  child: const Text('Reject', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)), // Ensure bold
                ),
              ),
              const SizedBox(width: 8),
              OutlinedButton(
                onPressed: () => _navigateToDetail(order),
                style: OutlinedButton.styleFrom( // Apply consistent button styling
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), // Changed to 12
                  side: BorderSide(color: AppColors.textMuted.withOpacity(0.5)), // Use AppColors.textMuted
                  padding: const EdgeInsets.symmetric(vertical: 16), // Consistent padding
                ),
                child: const Text('Details', style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold)), // Use AppColors.textMain and bold
              ),
            ],
          )
        ],
      ),
    );
  }
}
