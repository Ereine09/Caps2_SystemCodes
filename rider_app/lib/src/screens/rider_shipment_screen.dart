import 'package:flutter/material.dart';
import '../widgets/shipment_card.dart';

class RiderShipmentScreen extends StatelessWidget {
  const RiderShipmentScreen({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF7F8FA),
      appBar: AppBar(
        title: const Text('Assigned Shipments', style: TextStyle(color: Color(0xFF1E1F22), fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: Colors.white,
        elevation: 0.5,
        centerTitle: true,
      ),
      body: ListView(
        padding: const EdgeInsets.all(15),
        children: [
          ShipmentCard(
            orderNumber: 'DPS-2026-0091',
            customerName: 'Premium Poultry Feed (25kg Sacks)',
            address: 'Caloocan Client Hub',
            statusLabel: 'Transit',
            totalAmount: 'PHP 4,500.00',
            onTap: () {},
          ),
          ShipmentCard(
            orderNumber: 'DPS-2026-0084',
            customerName: 'Layer Chicken Vitamin Supplements',
            address: 'Novaliches Farm Outlet',
            statusLabel: 'On Process',
            totalAmount: 'PHP 1,250.00',
            onTap: () {},
          ),
        ],
      ),
    );
  }
}