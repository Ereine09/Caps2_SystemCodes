import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../state/auth_state.dart';
import '../constants/colors.dart';
import '../screens/rider_shipment_screen.dart';
import '../screens/remittance_screen.dart'; // Import the new screen
import '../ui/qr_scanner_screen.dart'; // Import the QR Scanner (Dio version)

class RiderBottomNavBar extends StatelessWidget {
  const RiderBottomNavBar({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: AppColors.textMain,
        borderRadius: BorderRadius.circular(40),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.12),
            blurRadius: 20,
            offset: const Offset(0, 8),
          )
        ],
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceAround,
        children: [
          _navItem(Icons.home_filled, 'Home', true),
GestureDetector(
            onTap: () {
              final token = context.read<AuthState>().token ?? '';
              Navigator.push(
                context,
                MaterialPageRoute(builder: (context) => RiderShipmentScreen(token: token)),
              );
            },
            child: _navItem(Icons.local_shipping_outlined, 'Shipment', false)),
          
          GestureDetector(
            onTap: () {
              // This now opens the QR Scanner screen
              final token = context.read<AuthState>().token;
              if (token != null) {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (context) => QRScannerScreen(token: token)),
                );
              } else {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(
                    content: Text('Authentication token missing. Please log in again.'),
                    backgroundColor: Colors.red,
                  ),
                );
              }
            },
            child: Container(
              padding: const EdgeInsets.all(12),
              decoration: const BoxDecoration(color: AppColors.accent, shape: BoxShape.circle),
              child: const Icon(Icons.qr_code_scanner, color: Colors.white, size: 22),
            ),
          ),
          
          GestureDetector(
            onTap: () {
              final token = context.read<AuthState>().token;
              if (token != null) {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (context) => RemittanceScreen(token: token)),
                );
              }
            },
            child: _navItem(Icons.payments_outlined, 'Remittance', false), // New Remittance Icon
          ),
          
          GestureDetector(
            onTap: () => context.read<AuthState>().logout(),
            child: _navItem(Icons.logout, 'Logout', false),
          ),
        ],
      ),
    );
  }

  Widget _navItem(IconData icon, String label, bool isActive) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, color: isActive ? Colors.white : AppColors.textMuted, size: 22),
        const SizedBox(height: 4),
        Text(label, style: TextStyle(color: isActive ? Colors.white : AppColors.textMuted, fontSize: 10)),
      ],
    );
  }
}