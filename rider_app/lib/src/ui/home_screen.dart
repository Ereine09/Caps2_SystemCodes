import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../state/auth_state.dart';
import '../widgets/orders_list_widget.dart'; // Panatilihing buhay ang lumang listahan mo

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _currentIndex = 0;

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();

    // Dynamic views base sa Bottom Navigation Bar selections
    final List<Widget> _screens = [
      _buildDashboardHome(context, auth),
      const Center(child: Text('Star Rider Analytics (Coming Soon)')),
      const Center(child: Text('Lead Submission Console (Coming Soon)')),
      _buildProfileView(context, auth),
    ];

    return Scaffold(
      backgroundColor: const Color(0xFFF7F9FC),
      body: _screens[_currentIndex],
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _currentIndex,
        type: BottomNavigationBarType.fixed,
        selectedItemColor: Colors.red,
        unselectedItemColor: Colors.grey,
        onTap: (index) => setState(() => _currentIndex = index),
        items: const [
          BottomNavigationBarItem(icon: Icon(Icons.home_filled), label: 'Home'),
          BottomNavigationBarItem(icon: Icon(Icons.emoji_events_outlined), label: 'Star Rider'),
          BottomNavigationBarItem(icon: Icon(Icons.assignment_turned_in_outlined), label: 'Lead Submission'),
          BottomNavigationBarItem(icon: Icon(Icons.account_circle_outlined), label: 'My'),
        ],
      ),
    );
  }

  // --- Main Core Dashboard (Eksaktong gayaya sa J&T Image Reference) ---
  Widget _buildDashboardHome(BuildContext context, AuthState auth) {
    return SingleChildScrollView(
      child: Column(
        children: [
          // 1. Top Red Header Frame
          Container(
            padding: const EdgeInsets.only(top: 50, left: 16, right: 16, bottom: 24),
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [Color(0xFFE52421), Color(0xFFF93D3A)],
              ),
              borderRadius: BorderRadius.only(
                bottomLeft: Radius.circular(24),
                bottomRight: Radius.circular(24),
              ),
            ),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(0.2),
                          borderRadius: BorderRadius.circular(30),
                        ),
                        child: const Row(
                          children: [
                            Icon(Icons.search, color: Colors.white, size: 20),
                            SizedBox(width: 8),
                            Text(
                              'One-click query of whole network parcels',
                              style: TextStyle(color: Colors.white70, fontSize: 13),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: const Text(
                        'Ping: 45ms',
                        style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                      ),
                    )
                  ],
                ),
                const SizedBox(height: 20),
                
                // 2. White Horizontal Metrics Box (Floating Row Cards)
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(16),
                    boxShadow: [
                      BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 10, offset: const Offset(0, 4)),
                    ],
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      _buildTopMetricItem(Icons.inventory_2_outlined, 'D2D(Non VIP)', 2, Colors.red),
                      _buildTopMetricItem(Icons.all_inbox_outlined, 'VIP Order', 0, Colors.red),
                      _buildTopMetricItem(Icons.assignment_outlined, 'To-do Task', 0, Colors.amber),
                      _buildTopMetricItem(
                        Icons.local_shipping_outlined, 
                        'Delivery Task', 
                        1, 
                        Colors.orange,
                        onTap: () => _openRiderOrders(context, auth), // Buksan ang orihinal mong listahan!
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),

          // 3. Scan & Operations Group Blocks
          Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _buildSectionTitle('Scan'),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 8),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
                  child: GridView.count(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    crossAxisCount: 4,
                    mainAxisSpacing: 16,
                    crossAxisSpacing: 4,
                    childAspectRatio: 0.85,
                    children: [
                      _buildGridMenuButton(Icons.playlist_add_check_circle, 'Clock In/Out', const Color(0xFF2998FF)),
                      _buildGridMenuButton(Icons.front_loader, 'Pickup', const Color(0xFFE52421)),
                      _buildGridMenuButton(Icons.dynamic_feed, 'Batch pickup', const Color(0xFFF93D3A)),
                      _buildGridMenuButton(
                        Icons.moped_rounded, 
                        'Delivery', 
                        const Color(0xFF3F51B5),
                        onTap: () => _openRiderOrders(context, auth), // Buksan din dito para mabilis!
                      ),
                      _buildGridMenuButton(Icons.assignment_late_outlined, 'Return POD', const Color(0xFFD32F2F)),
                      _buildGridMenuButton(Icons.replay_circle_filled_outlined, 'Return Eco sacks', Colors.orange),
                      _buildGridMenuButton(Icons.swap_horizontal_circle_outlined, 'RFID Transfer', const Color(0xFFE52421)),
                      _buildGridMenuButton(Icons.markunread_mailbox_outlined, 'Auto bagging', const Color(0xFFE52421)),
                      _buildGridMenuButton(Icons.local_shipping, 'Load & Dispatch', Colors.amber),
                    ],
                  ),
                ),

                const SizedBox(height: 20),
                _buildSectionTitle('General operation'),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 8),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
                  child: GridView.count(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    crossAxisCount: 4,
                    mainAxisSpacing: 16,
                    crossAxisSpacing: 4,
                    childAspectRatio: 0.85,
                    children: [
                      _buildGridMenuButton(Icons.bar_chart_rounded, 'Data', Colors.orange),
                      _buildGridMenuButton(Icons.monetization_on, 'Cebuana', const Color(0xFFE52421)),
                      _buildGridMenuButton(Icons.inventory_sharp, 'Security seal', const Color(0xFF4FC3F7)),
                      _buildGridMenuButton(Icons.print_rounded, 'Print', const Color(0xFF00B0FF)),
                    ],
                  ),
                ),
              ],
            ),
          )
        ],
      ),
    );
  }

  // --- Sub Widget Generators ---
  Widget _buildSectionTitle(String title) {
    return Text(
      title,
      style: const TextStyle(
        fontSize: 16, 
        fontWeight: FontWeight.bold, 
        color: Color(0xFF222222), // Malinis na dark gray/black para sa section headers
      ),
    );
  }

  Widget _buildTopMetricItem(IconData icon, String label, int count, Color iconColor, {VoidCallback? onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Stack(
            clipBehavior: Clip.none,
            children: [
              Icon(icon, size: 28, color: iconColor),
              if (count > 0)
                Positioned(
                  right: -6,
                  top: -6,
                  child: Container(
                    padding: const EdgeInsets.all(4),
                    decoration: const BoxDecoration(color: Colors.red, shape: BoxShape.circle),
                    constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                    child: Text(
                      '$count',
                      style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold),
                      textAlign: TextAlign.center,
                    ),
                  ),
                )
            ],
          ),
          const SizedBox(height: 6),
          Text(label, style: const TextStyle(fontSize: 11, color: Colors.black87, fontWeight: FontWeight.w500)),
        ],
      ),
    );
  }

  Widget _buildGridMenuButton(IconData icon, String title, Color bgColor, {VoidCallback? onTap}) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: bgColor,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Icon(icon, color: Colors.white, size: 26),
          ),
          const SizedBox(height: 6),
          Text(
            title,
            textAlign: TextAlign.center,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontSize: 11, 
              fontWeight: FontWeight.w500, 
              color: Color(0xFF333333), // Inalis ang typo na DE, ngayon ay malinis na dark color na
            ),
          ),
        ],
      ),
    );
  }

  // Action Drawer para sa original na Orders View nang walang nasisirang PHP system
  void _openRiderOrders(BuildContext context, AuthState auth) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (context) => Scaffold(
          appBar: AppBar(title: const Text('Assigned Deliveries')),
          body: OrdersListWidget(token: auth.token ?? ''),
        ),
      ),
    );
  }

  Widget _buildProfileView(BuildContext context, AuthState auth) {
    return Scaffold(
      appBar: AppBar(title: const Text('My Profile'), elevation: 0),
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const CircleAvatar(radius: 40, backgroundColor: Colors.red, child: Icon(Icons.person, size: 40, color: Colors.white)),
            const SizedBox(height: 12),
            Text('Welcome, ${auth.username ?? 'Rider'}!', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            Text('Rider ID: #${auth.userId ?? 0}', style: const TextStyle(color: Colors.grey)),
            const SizedBox(height: 30),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
              onPressed: () => context.read<AuthState>().clear(),
              icon: const Icon(Icons.logout),
              label: const Text('Logout Account'),
            ),
          ],
        ),
      ),
    );
  }
}