import 'package:flutter/material.dart';
import 'dart:convert';
import 'dart:typed_data';
import 'package:image_picker/image_picker.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';

/// Upload ePOD screen — lists the rider's out-for-delivery orders and lets them
/// capture & upload a proof-of-delivery photo for each.
class EpodUploadScreen extends StatefulWidget {
  final String token;
  const EpodUploadScreen({super.key, required this.token});

  @override
  State<EpodUploadScreen> createState() => _EpodUploadScreenState();
}

class _EpodUploadScreenState extends State<EpodUploadScreen> {
  late final ApiClient _api;
  final ImagePicker _imagePicker = ImagePicker();
  bool _loading = true;
  String? _error;
  List<dynamic> _orders = [];
  int _uploadingOrderId = 0;

  @override
  void initState() {
    super.initState();
    _api = ApiClient(baseUrl: baseUrl);
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final deliveries = await _api.getDeliveries(token: widget.token);
      // Only show orders that are out for delivery / ready for pickup (actionable).
      final actionable = deliveries.where((o) {
        final s = ((o as Map)['order_status']?.toString() ?? '').toLowerCase();
        return s == 'out_for_delivery' || s == 'ready_for_pickup' || s == 'to_ship' || s == 'to_receive';
      }).toList();
      if (mounted) {
        setState(() {
          _orders = actionable;
          _loading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e.toString().replaceAll('Exception: ', '');
          _loading = false;
        });
      }
    }
  }

  /// Picks a photo and uploads it as proof for the given order.
  Future<void> _uploadProof(Map<String, dynamic> order) async {
    final orderId = int.tryParse(order['order_id']?.toString() ?? '') ?? 0;
    if (orderId <= 0) return;

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
              leading: const Icon(Icons.photo_library, color: AppColors.primary),
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
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Could not open camera/gallery: $e'), backgroundColor: Colors.red),
      );
      return;
    }
    if (picked == null) return;

    Uint8List? bytes;
    try {
      bytes = await picked.readAsBytes();
    } catch (_) {
      bytes = null;
    }
    if (bytes == null || bytes.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not read image.'), backgroundColor: Colors.red),
      );
      return;
    }

    setState(() => _uploadingOrderId = orderId);
    try {
      final base64 = base64Encode(bytes);
      await _api.uploadProofOfDelivery(
        orderId: orderId,
        imageBase64: base64,
        token: widget.token,
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Proof of delivery uploaded.'), backgroundColor: Colors.green),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Upload failed: ${e.toString().replaceAll('Exception: ', '')}'), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _uploadingOrderId = 0);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Upload ePOD',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
        actions: [IconButton(icon: const Icon(Icons.refresh, color: Colors.black), onPressed: _load)],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.red)),
              const SizedBox(height: 16),
              ElevatedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }
    if (_orders.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.photo_camera_outlined, size: 56, color: AppColors.textMuted),
              SizedBox(height: 12),
              Text(
                'No out-for-delivery orders yet.\nWhen you have active deliveries, you can upload proof here.',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppColors.textMuted),
              ),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _orders.length,
        itemBuilder: (context, index) {
          final order = Map<String, dynamic>.from(_orders[index] as Map);
          final orderId = int.tryParse(order['order_id']?.toString() ?? '') ?? 0;
          final isUploading = _uploadingOrderId == orderId;
          final status = order['order_status_label']?.toString() ?? order['order_status']?.toString() ?? '';

          return Container(
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.cardBg,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Colors.grey.shade200),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.inventory_2_outlined, color: AppColors.primary, size: 20),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        order['order_number']?.toString() ?? 'Order',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppColors.primarySoftBg,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        status,
                        style: const TextStyle(color: AppColors.primary, fontSize: 11, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  order['customer_name']?.toString() ?? 'Unknown',
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
                ),
                Text(
                  order['address']?.toString() ?? '',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
                ),
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: isUploading ? null : () => _uploadProof(order),
                    icon: isUploading
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.photo_camera_outlined),
                    label: Text(isUploading ? 'Uploading...' : 'Upload Proof of Delivery'),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      side: const BorderSide(color: AppColors.primary),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      foregroundColor: AppColors.primary,
                    ),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
