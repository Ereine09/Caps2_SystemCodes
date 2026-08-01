import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../state/auth_state.dart';
import '../widgets/orders_list_widget.dart';
import '../widgets/rider_bottom_nav.dart';
import '../constants/colors.dart';
// 1. Import your QR Scanner screen
import '../screens/qr_scanner_screen.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        automaticallyImplyLeading: false,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Welcome Back,', style: TextStyle(color: AppColors.textMuted, fontSize: 12)),
            Text(
              auth.username ?? 'Rider',
              style: const TextStyle(color: AppColors.textMain, fontSize: 18, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        actions: [
          Container(
            margin: const EdgeInsets.only(right: 16),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: IconButton(
              icon: const Icon(Icons.notifications_none_outlined, color: AppColors.textMain),
              onPressed: () {},
            ),
          )
        ],
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20.0, vertical: 10),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Assigned Deliveries',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppColors.textMain),
                ),
                Text(
                  'Active Duty',
                  style: TextStyle(color: AppColors.primary.withValues(alpha: 0.8), fontWeight: FontWeight.w600),
                ),
              ],
            ),
          ),
          Expanded(
            child: OrdersListWidget(token: auth.token ?? ''),
          ),
        ],
      ),
      
      // 2. Changed FloatingActionButton to trigger the QR Scanner
      floatingActionButton: FloatingActionButton(
        backgroundColor: const Color(0xFF4a3e94),
        child: const Icon(Icons.qr_code_scanner_outlined),
        onPressed: () {
          final token = auth.token;
          if (token == null || token.isEmpty) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('Authentication token missing. Please log in again.'),
                backgroundColor: Colors.red,
              ),
            );
            return;
          }

          // Open QRScannerScreen and pass the rider token
          Navigator.of(context).push(
            MaterialPageRoute(
              builder: (context) => QRScannerScreen(token: token),
            ),
          );
        },
      ),

      bottomNavigationBar: const RiderBottomNavBar(),
    );
  }
}