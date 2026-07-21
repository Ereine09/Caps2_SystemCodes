import 'package:flutter/material.dart';

class RiderHomeDashboard extends StatelessWidget {
  const RiderHomeDashboard({Key? key}) : super(key: key);

  // Brand Color Palette para sa Darius Poultry Supply
  static const Color primaryColor = Color(0xFF003366); // Dark Royal Navy Blue
  static const Color accentColor = Color(0xFFFF9900);  // Vibrant Logistics Orange
  static const Color bgLight = Color(0xFFF7F9FC);      // Soft Slate Gray Background

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: bgLight,
      body: SingleChildScrollView(
        child: Column(
          children: [
            // Top Header Banner
            Container(
              padding: const EdgeInsets.fromLTRB(20, 50, 20, 25),
              decoration: const BoxDecoration(
                color: primaryColor,
                borderRadius: BorderRadius.only(
                  bottomLeft: Radius.circular(24),
                  bottomRight: Radius.circular(24),
                ),
              ),
              child: Column(
                children: [
                  // Search at Network Ping Row
                  Row(
                    children: [
                      Expanded(
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 16),
                          height: 45,
                          decoration: BoxDecoration(
                            color: Colors.white.withOpacity(0.15),
                            borderRadius: BorderRadius.circular(25),
                          ),
                          child: const Row(
                            children: [
                              Icon(Icons.search, color: Colors.white70),
                              SizedBox(width: 8),
                              Text(
                                'Query order details or parcels...',
                                style: TextStyle(color: Colors.white60, fontSize: 13),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(0.15),
                          borderRadius: BorderRadius.circular(20),
                        ),
                        child: const Text('Ping: 42ms', style: TextStyle(color: Colors.white, fontSize: 11)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),

                  // Task Summary Floating Card
                  Container(
                    padding: const EdgeInsets.symmetric(vertical: 15, horizontal: 10),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(16),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withOpacity(0.05),
                          blurRadius: 10,
                          offset: const Offset(0, 5),
                        )
                      ],
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                        _buildTopSummaryItem('D2D Orders', '2', primaryColor),
                        _buildTopSummaryItem('VIP Order', '0', accentColor),
                        _buildTopSummaryItem('To-do Task', '5', Colors.blue),
                        _buildTopSummaryItem('Delivery Task', '0', Colors.green),
                      ],
                    ),
                  ),
                ],
              ),
            ),

            // SECTION 1: SCAN OPERATIONS
            _buildSectionHeader('Scan Operations'),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 15),
              child: GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: 4,
                mainAxisSpacing: 15,
                crossAxisSpacing: 10,
                childAspectRatio: 0.85,
                children: [
                  _buildMenuIcon(Icons.alarm, 'Clock In/Out', Colors.blue),
                  _buildMenuIcon(Icons.inventory, 'Pickup', primaryColor),
                  _buildMenuIcon(Icons.layers, 'Batch Pickup', primaryColor),
                  _buildMenuIcon(Icons.moped, 'Delivery', accentColor), 
                  _buildMenuIcon(Icons.assignment_turned_in, 'Return POD', Colors.brown),
                  _buildMenuIcon(Icons.shopping_bag, 'Return Sacks', primaryColor),
                  _buildMenuIcon(Icons.sync_alt, 'RFID Transfer', primaryColor),
                  _buildMenuIcon(Icons.local_mall, 'Auto Bagging', primaryColor),
                ],
              ),
            ),

            // SECTION 2: GENERAL OPERATIONS
            _buildSectionHeader('General Operations'),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 15),
              child: GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: 4,
                mainAxisSpacing: 15,
                crossAxisSpacing: 10,
                childAspectRatio: 0.85,
                children: [
                  _buildMenuIcon(Icons.bar_chart, 'Data Reports', accentColor),
                  _buildMenuIcon(Icons.payments, 'COD Remit', primaryColor),
                  _buildMenuIcon(Icons.security, 'Security Seal', Colors.teal),
                  _buildMenuIcon(Icons.print, 'Print Label', Colors.blueGrey),
                ],
              ),
            ),
            const SizedBox(height: 100), 
          ],
        ),
      ),
    );
  }

  Widget _buildTopSummaryItem(String label, String count, Color themeColor) {
    return Column(
      children: [
        Stack(
          clipBehavior: Clip.none,
          children: [
            Icon(Icons.inventory_2_outlined, size: 28, color: themeColor),
            if (count != '0')
              Positioned(
                right: -6, top: -6,
                child: Container(
                  padding: const EdgeInsets.all(4),
                  decoration: const BoxDecoration(color: accentColor, shape: BoxShape.circle),
                  child: Text(count, style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold)),
                ),
              ),
          ],
        ),
        const SizedBox(height: 6),
        Text(label, style: const TextStyle(fontSize: 11, color: Colors.black87, fontWeight: FontWeight.w500)),
      ],
    );
  }

  Widget _buildSectionHeader(String title) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 12),
      child: Text(
        title,
        style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.black54, letterSpacing: 0.5),
      ),
    );
  }

  Widget _buildMenuIcon(IconData icon, String title, Color bgColor) {
    return InkWell(
      onTap: () {}, // Dito idudugtong ang click actions mo
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: bgColor.withOpacity(0.08),
              borderRadius: BorderRadius.circular(16),
            ),
            child: Icon(icon, color: bgColor, size: 24),
          ),
          const SizedBox(height: 8),
          Text(
            title,
            textAlign: TextAlign.center,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 11, color: Color(0xFF2D3142), fontWeight: FontWeight.w500),
          ),
        ],
      ),
    );
  }
}