import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';
import '../state/auth_state.dart';
import '../state/notification_state.dart';
import '../screens/rider_shipment_detail_screen.dart';
import '../screens/chat_screen.dart';
import 'rider_drawer.dart';
import 'notifications_screen.dart';

/// Main Delivery Dashboard — reorganized mirror of the reference rider app.
/// Uses the existing teal palette, keeps all rider features intact.
class RiderDeliveryDashboard extends StatefulWidget {
  final String token;
  const RiderDeliveryDashboard({super.key, required this.token});

  @override
  State<RiderDeliveryDashboard> createState() => _RiderDeliveryDashboardState();
}

class _RiderDeliveryDashboardState extends State<RiderDeliveryDashboard> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final ApiClient _apiClient = ApiClient(baseUrl: baseUrl);
  final TextEditingController _searchController = TextEditingController();

  bool _loading = true;
  String? _error;
  List<dynamic> _assignments = [];
  String _activeTab = 'To-do';
  String _searchQuery = '';
  String _sortOption = 'Default Sort';
  bool _statusLoading = false;

  static const List<Map<String, String>> _tabs = [
    {'label': 'To-do', 'statuses': 'pending,confirmed,processing,ready_for_pickup,out_for_delivery,to_ship,to_receive'},
    {'label': 'Delivered', 'statuses': 'completed'},
    {'label': 'On-Hold', 'statuses': 'cancelled'},
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final api = ApiClient(baseUrl: baseUrl);
      final res = await api.getJson<Map<String, dynamic>>(
        '/modules/rider/rider_orders_api.php',
        headers: {'Authorization': 'Bearer ${widget.token}'},
      );
      final body = res.data ?? {};
      if (body['success'] != true) {
        throw Exception(body['message'] ?? 'Failed to load orders');
      }
      final data = (body['data'] as Map?) ?? {};
      final assignments = (data['assignments'] as List?) ?? [];
      if (mounted) {
        setState(() => _assignments = assignments);
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// Toggles the rider's on-duty status via the backend.
  Future<void> _toggleStatus(bool value) async {
    final auth = context.read<AuthState>();
    setState(() => _statusLoading = true);
    try {
      await _apiClient.toggleRiderStatus(isOnDuty: value, token: widget.token);
      await auth.setOnDuty(value);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(value ? "You're now ONLINE and available for deliveries." : "You're now OFFLINE."),
            backgroundColor: value ? AppColors.statusDeliveredText : AppColors.textMuted,
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _statusLoading = false);
    }
  }

  void _openNotifications() {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NotificationsScreen()));
  }

  void _openDetail(Map<String, dynamic> order) {
    final orderId = int.tryParse(order['order_id']?.toString() ?? order['id']?.toString() ?? '') ?? 0;
    if (orderId <= 0) return;
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => RiderShipmentDetailScreen(
        orderId: orderId,
        apiClient: _apiClient,
        token: widget.token,
      ),
    ));
  }

  Future<void> _callCustomer(String? phone) async {
    final number = phone?.trim() ?? '';
    if (number.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No customer contact number available.'), backgroundColor: Colors.red),
      );
      return;
    }
    final uri = Uri(scheme: 'tel', path: number);
    try {
      await launchUrl(uri);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Unable to call: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  /// Opens a chat screen with the given customer. If no customer is provided
  /// (e.g. from the header icon), fall back to the first available active order's
  /// customer or show a helpful message.
  void _chatCustomer([Map<String, dynamic>? order]) {
    Map<String, dynamic>? target = order;
    if (target == null) {
      final active = _assignments.where((a) {
        final s = ((a as Map)['order_status']?.toString() ?? '').toLowerCase();
        return !['completed', 'cancelled'].contains(s);
      }).toList();
      if (active.isNotEmpty) {
        target = Map<String, dynamic>.from(active.first as Map);
      }
    }

    final customerId = int.tryParse(target?['customer_id']?.toString() ?? '') ?? 0;
    if (customerId <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('No active customer to chat with yet. Open a delivery to start messaging.'),
          backgroundColor: AppColors.primary,
        ),
      );
      return;
    }
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => ChatScreen(
        customerId: customerId,
        customerName: target?['customer_name']?.toString() ?? 'Customer',
        token: widget.token,
      ),
    ));
  }

  void _copyTracking(String tracking) {
    Clipboard.setData(ClipboardData(text: tracking));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Tracking ID copied to clipboard.'), backgroundColor: Colors.green),
    );
  }

  List<String> _statusListForTab(String tabLabel) {
    final tab = _tabs.firstWhere((t) => t['label'] == tabLabel);
    return (tab['statuses'] ?? '').split(',');
  }

  List<dynamic> _filteredOrders() {
    final statuses = _statusListForTab(_activeTab);
    final q = _searchQuery.toLowerCase();
    final list = _assignments.where((item) {
      final m = item as Map;
      final status = (m['order_status']?.toString() ?? '').toLowerCase();
      if (!statuses.contains(status)) return false;
      if (q.isEmpty) return true;
      final orderNo = (m['order_number']?.toString() ?? '').toLowerCase();
      final name = (m['customer_name']?.toString() ?? '').toLowerCase();
      return orderNo.contains(q) || name.contains(q);
    }).toList();

    if (_sortOption == 'Newest') {
      list.sort((a, b) {
        final aa = (a as Map)['created_at']?.toString() ?? '';
        final bb = (b as Map)['created_at']?.toString() ?? '';
        return bb.compareTo(aa);
      });
    } else if (_sortOption == 'Oldest') {
      list.sort((a, b) {
        final aa = (a as Map)['created_at']?.toString() ?? '';
        final bb = (b as Map)['created_at']?.toString() ?? '';
        return aa.compareTo(bb);
      });
    }
    return list;
  }

  int _countForTab(String label) {
    final statuses = _statusListForTab(label);
    return _assignments.where((item) {
      final status = ((item as Map)['order_status']?.toString() ?? '').toLowerCase();
      return statuses.contains(status);
    }).length;
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final notifCount = context.watch<NotificationState>().unreadCount;

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: AppColors.backgroundGrey,
drawer: RiderDrawer(scaffoldKey: _scaffoldKey),
      body: Column(
        children: [
          _buildHeader(auth, notifCount),
          Expanded(child: _buildBody()),
        ],
      ),
    );
  }

  Widget _buildHeader(AuthState auth, int notifCount) {
    return Container(
      color: Colors.white,
      padding: EdgeInsets.only(top: MediaQuery.of(context).padding.top),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Row(
              children: [
                IconButton(
                  icon: const Icon(Icons.menu, color: AppColors.textMain),
                  onPressed: () => _scaffoldKey.currentState?.openDrawer(),
                ),
                const Text(
                  'Delivery',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: AppColors.textMain),
                ),
                const SizedBox(width: 4),
                const Icon(Icons.arrow_drop_down, color: AppColors.textMuted),
                const Spacer(),
                // On-duty toggle (existing feature preserved)
                _statusLoading
                    ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                    : GestureDetector(
                        onTap: () => _toggleStatus(!auth.isOnDuty),
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: auth.isOnDuty ? AppColors.statusDelivered : AppColors.primarySoftBg,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            children: [
                              Icon(
                                auth.isOnDuty ? Icons.online_prediction : Icons.offline_bolt,
                                size: 16,
                                color: auth.isOnDuty ? AppColors.statusDeliveredText : AppColors.textMuted,
                              ),
                              const SizedBox(width: 4),
                              Text(
                                auth.isOnDuty ? 'Online' : 'Offline',
                                style: TextStyle(
                                  color: auth.isOnDuty ? AppColors.statusDeliveredText : AppColors.textMuted,
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                const SizedBox(width: 4),
                IconButton(
                  icon: const Icon(Icons.search, color: AppColors.textMain),
                  onPressed: () => _focusSearch(),
                ),
                // Notification icon with badge
                IconButton(
                  icon: Stack(
                    clipBehavior: Clip.none,
                    children: [
                      const Icon(Icons.notifications_none, color: AppColors.textMain),
                      if (notifCount > 0)
                        Positioned(
                          right: -4,
                          top: -4,
                          child: Container(
                            padding: const EdgeInsets.all(3),
                            constraints: const BoxConstraints(minWidth: 14, minHeight: 14),
                            decoration: const BoxDecoration(color: AppColors.statusDangerText, shape: BoxShape.circle),
                            child: Text(
                              notifCount > 99 ? '99+' : '$notifCount',
                              style: const TextStyle(color: Colors.white, fontSize: 8, fontWeight: FontWeight.bold),
                              textAlign: TextAlign.center,
                            ),
                          ),
                        ),
                    ],
                  ),
                  onPressed: _openNotifications,
                ),
                // Chat icon
                IconButton(
                  icon: const Icon(Icons.chat_bubble_outline, color: AppColors.textMain),
                  onPressed: _chatCustomer,
                ),
              ],
            ),
          ),
          // Search field (shown when actively searching)
          if (_searchQuery.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
              child: TextField(
                controller: _searchController,
                autofocus: true,
                onChanged: (val) => setState(() => _searchQuery = val),
                decoration: InputDecoration(
                  hintText: 'Search order or customer',
                  prefixIcon: const Icon(Icons.search, color: AppColors.textMuted),
                  suffixIcon: IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () {
                      _searchController.clear();
                      setState(() => _searchQuery = '');
                    },
                  ),
                  filled: true,
                  fillColor: AppColors.backgroundGrey,
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
                ),
              ),
            ),
        ],
      ),
    );
  }

  void _focusSearch() {
    setState(() => _searchQuery = _searchQuery.isEmpty ? ' ' : _searchQuery);
  }

  Widget _buildBody() {
    return RefreshIndicator(
      onRefresh: _load,
      child: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    const SizedBox(height: 120),
                    Center(child: Text(_error!, style: const TextStyle(color: Colors.red))),
                    const SizedBox(height: 12),
                    Center(child: ElevatedButton(onPressed: _load, child: const Text('Retry'))),
                  ],
                )
              : ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.only(bottom: 96),
                  children: [
                    _buildStatusTabs(),
                    const SizedBox(height: 12),
                    _buildSubFilters(),
                    const SizedBox(height: 12),
                    const _MapBanner(),
                    const SizedBox(height: 12),
                    _buildParcelList(),
                  ],
                ),
    );
  }

  Widget _buildStatusTabs() {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: _tabs.map((tab) {
          final label = tab['label']!;
          final isActive = _activeTab == label;
          final count = _countForTab(label);
          return Expanded(
            child: GestureDetector(
              onTap: () => setState(() => _activeTab = label),
              child: Container(
                padding: const EdgeInsets.symmetric(vertical: 12),
                decoration: BoxDecoration(
                  border: Border(
                    bottom: BorderSide(
                      color: isActive ? AppColors.primary : Colors.transparent,
                      width: 3,
                    ),
                  ),
                ),
                child: Center(
                  child: Text.rich(
                    TextSpan(
                      text: '$label ',
                      style: TextStyle(
                        color: isActive ? AppColors.primary : AppColors.textMuted,
                        fontWeight: isActive ? FontWeight.bold : FontWeight.w600,
                        fontSize: 14,
                      ),
                      children: [
                        TextSpan(
                          text: '($count)',
                          style: TextStyle(
                            color: isActive ? AppColors.primary : AppColors.textMuted,
                            fontSize: 12,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          );
        }).toList(),
      ),
    );
  }

  Widget _buildSubFilters() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Row(
        children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: () {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Prioritise view enabled.'), backgroundColor: AppColors.primary),
                );
              },
              icon: const Icon(Icons.flag, color: AppColors.primary, size: 18),
              label: const Text('Prioritise', style: TextStyle(color: AppColors.primary)),
              style: OutlinedButton.styleFrom(
                side: BorderSide(color: AppColors.primary.withValues(alpha: 0.5)),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                padding: const EdgeInsets.symmetric(vertical: 8),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.primary.withValues(alpha: 0.5)),
                borderRadius: BorderRadius.circular(20),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  value: _sortOption,
                  isExpanded: true,
                  icon: const Icon(Icons.arrow_drop_down, color: AppColors.primary),
                  style: const TextStyle(color: AppColors.textMain, fontSize: 13),
                  onChanged: (val) => setState(() => _sortOption = val ?? 'Default Sort'),
                  items: ['Default Sort', 'Newest', 'Oldest']
                      .map((o) => DropdownMenuItem(value: o, child: Text(o)))
                      .toList(),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildParcelList() {
    final orders = _filteredOrders();
    if (orders.isEmpty) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 40),
        child: Center(child: Text('No orders for this status.', style: TextStyle(color: AppColors.textMuted))),
      );
    }
    return Column(
      children: orders.map((item) {
        final order = item as Map<String, dynamic>;
        return _ParcelCard(
          tracking: order['order_number']?.toString() ?? 'N/A',
          address: order['address']?.toString() ?? order['delivery_address']?.toString() ?? 'No address',
          recipient: order['customer_name']?.toString() ?? 'Unknown',
          status: order['order_status_label']?.toString() ?? (order['order_status']?.toString() ?? ''),
          phone: order['phone']?.toString() ?? '',
          onTap: () => _openDetail(order),
          onCopy: () => _copyTracking(order['order_number']?.toString() ?? ''),
onCall: () => _callCustomer(order['phone']?.toString()),
          onChat: () => _chatCustomer(order),
        );
      }).toList(),
    );
  }
}

class _ParcelCard extends StatelessWidget {
  final String tracking;
  final String address;
  final String recipient;
  final String status;
  final String phone;
  final VoidCallback onTap;
  final VoidCallback onCopy;
  final VoidCallback onCall;
  final VoidCallback onChat;

  const _ParcelCard({
    required this.tracking,
    required this.address,
    required this.recipient,
    required this.status,
    required this.phone,
    required this.onTap,
    required this.onCopy,
    required this.onCall,
    required this.onChat,
  });

  @override
  Widget build(BuildContext context) {
    final clean = status.toLowerCase();
    Color badgeBg = AppColors.statusDelivered;
    Color badgeText = AppColors.statusDeliveredText;
    if (clean.contains('pending') || clean.contains('process') || clean.contains('out_for_delivery') || clean.contains('ready')) {
      badgeBg = AppColors.statusTransit;
      badgeText = AppColors.statusTransitText;
    } else if (clean.contains('cancel')) {
      badgeBg = AppColors.statusDanger;
      badgeText = AppColors.statusDangerText;
    }

    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: Colors.grey.shade200),
          boxShadow: [
            BoxShadow(color: Colors.black.withValues(alpha: 0.03), blurRadius: 8, offset: const Offset(0, 2)),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Top row: tracking + copy
            Row(
              children: [
                const Icon(Icons.inventory_2_outlined, color: AppColors.primary, size: 18),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    tracking,
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.textMain),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.copy, color: AppColors.textMuted, size: 18),
                  onPressed: onCopy,
                  visualDensity: VisualDensity.compact,
                ),
              ],
            ),
            const Divider(height: 12),
            // Body: pin + address + recipient + status
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.location_on_outlined, color: AppColors.primary, size: 18),
                const SizedBox(width: 6),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        address,
                        style: const TextStyle(color: AppColors.textMain, fontSize: 13, fontWeight: FontWeight.w500),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          const Icon(Icons.person_outline, color: AppColors.textMuted, size: 14),
                          const SizedBox(width: 4),
                          Expanded(
                            child: Text(
                              recipient,
                              style: const TextStyle(color: AppColors.textSoft, fontSize: 13),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(color: badgeBg, borderRadius: BorderRadius.circular(20)),
                        child: Text(
                          status.isEmpty ? 'N/A' : status,
                          style: TextStyle(color: badgeText, fontSize: 11, fontWeight: FontWeight.bold),
                        ),
                      ),
                    ],
                  ),
                ),
                // Quick action buttons
                Column(
                  children: [
                    _CircleAction(
                      icon: Icons.phone,
                      onTap: phone.isNotEmpty ? onCall : null,
                      color: AppColors.primary,
                    ),
                    const SizedBox(height: 10),
                    _CircleAction(icon: Icons.chat_bubble_outline, onTap: onChat, color: AppColors.textMain),
                  ],
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _CircleAction extends StatelessWidget {
  final IconData icon;
  final Color color;
  final VoidCallback? onTap;

  const _CircleAction({required this.icon, required this.color, this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      customBorder: const CircleBorder(),
      child: Container(
        width: 38,
        height: 38,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          border: Border.all(color: color.withValues(alpha: 0.4)),
          color: color.withValues(alpha: 0.08),
        ),
        child: Icon(icon, color: color, size: 18),
      ),
    );
  }
}

class _MapBanner extends StatelessWidget {
  const _MapBanner();

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 16),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.primarySoftBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.3)),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.primary,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.map_outlined, color: Colors.white, size: 22),
          ),
          const SizedBox(width: 12),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Display in Map',
                  style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold, fontSize: 14),
                ),
                SizedBox(height: 2),
                Text(
                  'View all active deliveries on a map',
                  style: TextStyle(color: AppColors.textMuted, fontSize: 12),
                ),
              ],
            ),
          ),
          const Icon(Icons.chevron_right, color: AppColors.primary),
        ],
      ),
    );
  }
}
