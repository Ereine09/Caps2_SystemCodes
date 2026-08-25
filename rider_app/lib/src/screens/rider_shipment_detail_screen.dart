import 'package:flutter/material.dart';
import 'dart:convert';
import 'dart:typed_data';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:image_picker/image_picker.dart';
import '../api/api_client.dart';
import '../screens/order.dart';
import '../state/auth_state.dart';
import '../constants/colors.dart'; // Import AppColors
import '../ui/qr_scanner_screen.dart'; // Ensure this path is correct

class RiderShipmentDetailScreen extends StatefulWidget {
  final int orderId;
  final ApiClient apiClient;
  final String token;

  const RiderShipmentDetailScreen({
    super.key,
    required this.orderId,
    required this.apiClient,
    this.token = '',
  });

  @override
  State<RiderShipmentDetailScreen> createState() =>
      _RiderShipmentDetailScreenState();
}

class _RiderShipmentDetailScreenState extends State<RiderShipmentDetailScreen> {
  late Future<Order> _detailsFuture;
  final bool _isVerifyingQR = false;
  bool _isUploadingProof = false;
  final ImagePicker _imagePicker = ImagePicker();

  @override
  void initState() {
    super.initState();
    _detailsFuture = _fetchDetails();
  }

  /// Opens the image picker to capture/select a proof-of-delivery photo and
  /// then uploads it along with optional notes to the backend.
  Future<void> _captureProofOfDelivery(int orderId) async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera, color: AppColors.primary),
              title: const Text('Take Photo'),
              onTap: () => Navigator.of(context).pop(ImageSource.camera),
            ),
            ListTile(
              leading:
                  const Icon(Icons.photo_library, color: AppColors.primary),
              title: const Text('Choose from Gallery'),
              onTap: () => Navigator.of(context).pop(ImageSource.gallery),
            ),
          ],
        ),
      ),
    );

    if (source == null) return;

    final XFile? picked;
    try {
      picked = await _imagePicker.pickImage(
        source: source,
        maxWidth: 1600,
        maxHeight: 1600,
        imageQuality: 70,
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text('Could not open camera/gallery: $e'),
              backgroundColor: Colors.red),
        );
      }
      return;
    }

    if (picked == null || !mounted) return;

    Uint8List? bytes;
    try {
      bytes = await picked.readAsBytes();
    } catch (_) {
      bytes = null;
    }
    if (bytes == null || bytes.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Could not read the captured image.'),
            backgroundColor: Colors.red),
      );
      return;
    }

    // Optionally allow the rider to add a note before uploading.
    final notes = await _promptForNotes();
    if (notes == null) return; // cancelled

    setState(() {
      _isUploadingProof = true;
    });

    try {
      final base64 = base64Encode(bytes);
      await widget.apiClient.uploadProofOfDelivery(
        orderId: orderId,
        imageBase64: base64,
        notes: notes,
        token: widget.token,
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Proof of delivery uploaded successfully.'),
              backgroundColor: Colors.green),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(
                  'Upload failed: ${e.toString().replaceFirst("Exception: ", "")}'),
              backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) {
        setState(() {
          _isUploadingProof = false;
        });
      }
    }
  }

  /// Prompts the rider for an optional delivery note. Returns null if cancelled.
  Future<String?> _promptForNotes() async {
    final controller = TextEditingController();
    final result = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delivery Note (Optional)'),
        content: TextField(
          controller: controller,
          maxLines: 3,
          decoration: const InputDecoration(
            hintText: 'e.g. Left at front desk, handed to receptionist...',
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(null),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(context).pop(controller.text.trim()),
            child: const Text('Upload'),
          ),
        ],
      ),
    );
    return result;
  }

  Future<Order> _fetchDetails() async {
    try {
      final data = await widget.apiClient
          .getDeliveryDetails(widget.orderId, token: widget.token);
      return Order.fromJson(data);
    } catch (e) {
      throw Exception('Failed to load order details: $e');
    }
  }

  /// Launches the device dialer with the customer's phone number.
  Future<void> _callCustomer(String? phone) async {
    final number = phone?.trim() ?? '';
    if (number.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('No customer contact number available.'),
            backgroundColor: Colors.red),
      );
      return;
    }

    final uri = Uri(scheme: 'tel', path: number);
    try {
      final launched = await launchUrl(uri);
      if (!launched) {
        throw Exception('Could not launch dialer');
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(
                'Unable to call: ${e.toString().replaceFirst("Exception: ", "")}'),
            backgroundColor: Colors.red),
      );
    }
  }

  /// Opens Google Maps with the delivery address as the destination.
  Future<void> _navigateToDelivery(String address) async {
    final query = address.trim();
    if (query.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('No delivery address available.'),
            backgroundColor: Colors.red),
      );
      return;
    }

    final mapsUri = Uri.parse(
        'https://www.google.com/maps/search/?api=1&query=${Uri.encodeQueryComponent(query)}');
    try {
      final launched =
          await launchUrl(mapsUri, mode: LaunchMode.externalApplication);
      if (!launched) {
        throw Exception('Could not open maps');
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(
                'Unable to open maps: ${e.toString().replaceFirst("Exception: ", "")}'),
            backgroundColor: Colors.red),
      );
    }
  }

  void _navigateToQRScanner() async {
    final authToken = widget.token.isNotEmpty
        ? widget.token
        : (context.read<AuthState>().token ?? '');

    final qrConfirmed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
          builder: (context) => QRScannerScreen(
                token: authToken,
                returnToCaller: true,
              )),
    );

    if (qrConfirmed == true) {
      if (mounted) Navigator.of(context).pop(true);
    }
  }

  /// Maps an order status key to a display-friendly label.
  String _statusLabel(String status) {
    switch (status.toLowerCase()) {
      case 'pending':
        return 'Pending';
      case 'confirmed':
        return 'Confirmed';
      case 'processing':
        return 'Processing';
      case 'ready_for_pickup':
        return 'Ready for Pickup';
      case 'out_for_delivery':
        return 'Out for Delivery';
      case 'to_ship':
        return 'To Ship';
      case 'to_receive':
        return 'To Receive';
      case 'reviews':
        return 'Reviews';
      case 'completed':
        return 'Completed';
      case 'cancelled':
        return 'Cancelled';
      default:
        return status.replaceAll('_', ' ');
    }
  }

  /// Returns badge colors for a given status key.
  (Color, Color) _statusBadge(String status) {
    final s = status.toLowerCase();
    if (s == 'completed') {
      return (AppColors.statusDelivered, AppColors.statusDeliveredText);
    }
    if (s == 'cancelled') {
      return (AppColors.statusDanger, AppColors.statusDangerText);
    }
    if (s == 'out_for_delivery' ||
        s == 'ready_for_pickup' ||
        s == 'to_ship' ||
        s == 'to_receive') {
      return (AppColors.statusTransit, AppColors.statusTransitText);
    }
    return (AppColors.statusProcess, AppColors.statusProcessText);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.backgroundGrey,
      appBar: AppBar(
        title: const Text(
          'Shipment Details',
          style:
              TextStyle(fontWeight: FontWeight.bold, color: AppColors.textMain),
        ),
        backgroundColor: Colors.white,
        elevation: 0.5,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: AppColors.textMain),
          onPressed: () => Navigator.of(context).pop(),
        ),
      ),
      body: FutureBuilder<Order>(
        future: _detailsFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.error_outline,
                        color: AppColors.statusDangerText, size: 48),
                    const SizedBox(height: 12),
                    Text(
                      '${snapshot.error}',
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: AppColors.textMuted),
                    ),
                    const SizedBox(height: 16),
                    ElevatedButton(
                      onPressed: () =>
                          setState(() => _detailsFuture = _fetchDetails()),
                      child: const Text('Retry'),
                    ),
                  ],
                ),
              ),
            );
          }
          if (!snapshot.hasData) {
            return const Center(child: Text('No order details found.'));
          }

          final order = snapshot.data!;
          final currencyFormat =
              NumberFormat.currency(locale: 'en_PH', symbol: '₱');
          final (badgeBg, badgeText) = _statusBadge(order.orderStatus);
          final statusLabel = _statusLabel(order.orderStatus);

          return Column(
            children: [
              Expanded(
                child: RefreshIndicator(
                  onRefresh: () async {
                    setState(() => _detailsFuture = _fetchDetails());
                    await _detailsFuture;
                  },
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(16),
                    children: [
                      // --- Hero summary card ---
                      _buildHeroCard(order, currencyFormat, badgeBg, badgeText,
                          statusLabel),
                      const SizedBox(height: 16),

                      // --- Customer & Delivery card ---
                      _buildCard(
                        title: 'Customer & Delivery',
                        icon: Icons.person_outline,
                        children: [
                          _buildInfoRow(Icons.person_outline, 'Customer',
                              order.customerName),
                          _buildInfoRow(
                            Icons.phone_outlined,
                            'Contact',
                            order.customerPhone ?? 'N/A',
                          ),
                          _buildInfoRow(
                            Icons.location_on_outlined,
                            'Address',
                            order.deliveryAddress,
                            isAddress: true,
                          ),
                          if (order.deliveryPhone != null &&
                              order.deliveryPhone!.isNotEmpty)
                            _buildInfoRow(
                              Icons.phone_forwarded_outlined,
                              'Delivery Phone',
                              order.deliveryPhone!,
                            ),
                          const SizedBox(height: 12),
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton.icon(
                                  icon: const Icon(Icons.phone, size: 18),
                                  label: const Text('Call'),
                                  onPressed: () =>
                                      _callCustomer(order.customerPhone),
                                  style: OutlinedButton.styleFrom(
                                    padding: const EdgeInsets.symmetric(
                                        vertical: 14),
                                    side: const BorderSide(
                                        color: AppColors.statusDeliveredText),
                                    shape: RoundedRectangleBorder(
                                        borderRadius:
                                            BorderRadius.circular(12)),
                                    foregroundColor:
                                        AppColors.statusDeliveredText,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: OutlinedButton.icon(
                                  icon: const Icon(Icons.navigation, size: 18),
                                  label: const Text('Navigate'),
                                  onPressed: () => _navigateToDelivery(
                                      order.deliveryAddress),
                                  style: OutlinedButton.styleFrom(
                                    padding: const EdgeInsets.symmetric(
                                        vertical: 14),
                                    side: const BorderSide(
                                        color: AppColors.primary),
                                    shape: RoundedRectangleBorder(
                                        borderRadius:
                                            BorderRadius.circular(12)),
                                    foregroundColor: AppColors.primary,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),

                      // --- Order Info card ---
                      _buildCard(
                        title: 'Order Info',
                        icon: Icons.receipt_long_outlined,
                        children: [
                          _buildInfoRow(
                            Icons.confirmation_number_outlined,
                            'Order No.',
                            order.orderNumber,
                          ),
                          _buildInfoRow(
                            Icons.payments_outlined,
                            'Payment',
                            order.paymentMethod.toUpperCase(),
                          ),
                          _buildInfoRow(
                            Icons.category_outlined,
                            'Type',
                            order.fulfillmentType
                                .replaceAll('_', ' ')
                                .toUpperCase(),
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),

                      // --- Order Items card ---
                      _buildCard(
                        title: 'Order Items',
                        icon: Icons.shopping_bag_outlined,
                        children: [
                          if (order.items.isEmpty)
                            const Padding(
                              padding: EdgeInsets.symmetric(vertical: 8),
                              child: Text(
                                'No items found for this order.',
                                style: TextStyle(color: AppColors.textMuted),
                              ),
                            )
                          else
                            ...order.items.map((item) {
                              return Padding(
                                padding:
                                    const EdgeInsets.symmetric(vertical: 6),
                                child: Row(
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 10, vertical: 6),
                                      decoration: BoxDecoration(
                                        color: AppColors.primarySoftBg,
                                        borderRadius: BorderRadius.circular(8),
                                      ),
                                      child: Text(
                                        'x${item.quantity}',
                                        style: const TextStyle(
                                          color: AppColors.primary,
                                          fontWeight: FontWeight.bold,
                                          fontSize: 13,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Text(
                                        item.productName,
                                        style: const TextStyle(
                                          color: AppColors.textMain,
                                          fontWeight: FontWeight.w500,
                                          fontSize: 14,
                                        ),
                                      ),
                                    ),
                                    Text(
                                      currencyFormat.format(item.totalPrice),
                                      style: const TextStyle(
                                        color: AppColors.textMain,
                                        fontWeight: FontWeight.bold,
                                        fontSize: 14,
                                      ),
                                    ),
                                  ],
                                ),
                              );
                            }),
                          const Divider(height: 20),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              const Text(
                                'Total',
                                style: TextStyle(
                                  color: AppColors.textSoft,
                                  fontWeight: FontWeight.w600,
                                  fontSize: 14,
                                ),
                              ),
                              Text(
                                currencyFormat.format(order.total),
                                style: const TextStyle(
                                  color: AppColors.primary,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 18,
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              // --- Action Button Area ---
              if (order.orderStatus == 'out_for_delivery') ...[
                _buildConfirmButton(),
                _buildProofButton(order.id),
              ],
            ],
          );
        },
      ),
    );
  }

  /// Teal gradient hero card with the tracking number, status badge, and total.
  Widget _buildHeroCard(
    Order order,
    NumberFormat currencyFormat,
    Color badgeBg,
    Color badgeText,
    String statusLabel,
  ) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AppColors.primary, Color(0xFF006064)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.25),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.inventory_2_outlined,
                  color: Colors.white70, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  order.orderNumber,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                    fontSize: 15,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              // Status badge (light chip on gradient)
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  statusLabel.toUpperCase(),
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
          const Text(
            'Total Amount',
            style: TextStyle(color: Colors.white70, fontSize: 13),
          ),
          const SizedBox(height: 4),
          Text(
            currencyFormat.format(order.total),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 32,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: badgeBg,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  statusLabel,
                  style: TextStyle(
                    color: badgeText,
                    fontSize: 12,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Shared styled card container matching the dashboard aesthetic.
  Widget _buildCard({
    required String title,
    required IconData icon,
    required List<Widget> children,
  }) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.grey.shade200),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: AppColors.primarySoftBg,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(icon, color: AppColors.primary, size: 18),
              ),
              const SizedBox(width: 10),
              Text(
                title,
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: AppColors.textMain,
                ),
              ),
            ],
          ),
          const Divider(height: 24),
          ...children,
        ],
      ),
    );
  }

  /// Row with a leading icon, label, and value.
  Widget _buildInfoRow(IconData icon, String label, String value,
      {bool isAddress = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment:
            isAddress ? CrossAxisAlignment.start : CrossAxisAlignment.center,
        children: [
          SizedBox(
            width: 30,
            child: Icon(icon, color: AppColors.primary, size: 18),
          ),
          SizedBox(
            width: 100,
            child: Text(
              label,
              style: const TextStyle(color: AppColors.textMuted, fontSize: 13),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                color: AppColors.textMain,
                fontWeight: FontWeight.w600,
                fontSize: 13,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildProofButton(int orderId) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      child: SizedBox(
        width: double.infinity,
        child: OutlinedButton.icon(
          icon: _isUploadingProof
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2.0))
              : const Icon(Icons.photo_camera_outlined),
          label: Text(
              _isUploadingProof ? 'Uploading...' : 'Upload Proof of Delivery'),
          onPressed:
              _isUploadingProof ? null : () => _captureProofOfDelivery(orderId),
          style: OutlinedButton.styleFrom(
            padding: const EdgeInsets.symmetric(vertical: 16),
            side: const BorderSide(color: AppColors.primary),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            foregroundColor: AppColors.primary,
          ),
        ),
      ),
    );
  }

  Widget _buildConfirmButton() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
      child: SizedBox(
        width: double.infinity,
        child: ElevatedButton.icon(
          icon: _isVerifyingQR
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                      color: Colors.white, strokeWidth: 2.0))
              : const Icon(Icons.qr_code_scanner),
          label: Text(_isVerifyingQR ? 'Verifying...' : 'Confirm with QR Code'),
          onPressed: _isVerifyingQR ? null : _navigateToQRScanner,
        ),
      ),
    );
  }
}
