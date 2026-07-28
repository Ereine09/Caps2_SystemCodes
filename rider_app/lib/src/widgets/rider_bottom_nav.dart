import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../state/auth_state.dart';
import '../constants/colors.dart';
import '../screens/rider_shipment_screen.dart';

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
            color: Colors.black.withValues(alpha: 0.12),
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
              Navigator.push(
                context,
                MaterialPageRoute(builder: (context) => const RiderShipmentScreen()),
              );
            },
            child: _navItem(Icons.local_shipping_outlined, 'Shipment', false)),
          
          GestureDetector(
            onTap: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Scan or Action Feature Triggered')),
              );
            },
            child: Container(
              padding: const EdgeInsets.all(12),
              decoration: const BoxDecoration(color: AppColors.accentOrange, shape: BoxShape.circle),
              child: const Icon(Icons.add, color: Colors.white, size: 22),
            ),
          ),
          
          _navItem(Icons.analytics_outlined, 'History', false),
          
          GestureDetector(
            onTap: () => context.read<AuthState>().clear(),
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