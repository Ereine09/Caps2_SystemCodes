import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';

class QRScannerScreen extends StatefulWidget {
  final String token;
  final bool returnToCaller;
  const QRScannerScreen(
      {super.key, required this.token, this.returnToCaller = false});

  @override
  State<QRScannerScreen> createState() => _QRScannerScreenState();
}

class _QRScannerScreenState extends State<QRScannerScreen> {
  bool isProcessing = false;
  final MobileScannerController cameraController = MobileScannerController();
  final String _baseUrl = baseUrl;

  String _statusLabel(dynamic status) {
    switch (status.toString().toLowerCase()) {
      case 'out_for_delivery':
        return 'Out for Delivery';
      case 'to_ship':
        return 'To Ship';
      case 'to_receive':
        return 'To Receive';
      case 'completed':
        return 'Completed';
      case 'cancelled':
        return 'Cancelled';
      default:
        return status.toString().replaceAll('_', ' ');
    }
  }

  List<String> _nextStatuses(String status) {
    switch (status.toLowerCase()) {
      case 'to_ship':
        return ['to_receive'];
      case 'to_receive':
        return ['out_for_delivery'];
      case 'out_for_delivery':
        return ['completed'];
      default:
        return [];
    }
  }

  Future<void> _handleScan(String qrCodeData) async {
    setState(() => isProcessing = true);
    await cameraController.stop();

    try {
      final qrData = jsonDecode(qrCodeData);
      if (qrData is! Map || qrData['delivery_id'] == null) {
        throw Exception('Invalid QR code format.');
      }

      final api = ApiClient(baseUrl: _baseUrl);
      final preview =
          await api.verifyDeliveryQR(qrCodeData, token: widget.token);
      final previewData = Map<String, dynamic>.from(preview['data'] ?? {});
      final currentStatus = previewData['order_status']?.toString() ?? '';
      final nextStatuses = _nextStatuses(currentStatus);
      if (nextStatuses.isEmpty) {
        throw Exception('This order is not ready for a rider status update.');
      }

      if (!mounted) return;
      final selectedStatus = await showDialog<String>(
        context: context,
        barrierDismissible: false,
        builder: (dialogContext) => AlertDialog(
          title: const Text('Update Delivery Status'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Order #${previewData['order_number'] ?? 'Unknown'}'),
              const SizedBox(height: 8),
              Text(
                  'Customer: ${previewData['customer_name'] ?? 'Unknown customer'}'),
              Text(
                  'Order Status: ${_statusLabel(previewData['order_status'])}'),
              const SizedBox(height: 16),
                if (currentStatus == 'to_ship')
                  const Text('Ready to Pick Up at Shop / Warehouse'),
                const SizedBox(height: 16),
                DropdownButtonFormField<String>(
                  initialValue: nextStatuses.first,
                  decoration: const InputDecoration(labelText: 'Next status'),
                  items: nextStatuses
                      .map((status) => DropdownMenuItem(
                            value: status,
                            child: Text(_statusLabel(status)),
                          ))
                      .toList(),
                  onChanged: (_) {},
                ),
              const SizedBox(height: 8),
                Text(currentStatus == 'out_for_delivery'
                    ? 'Confirm that the customer received this order.'
                    : 'Confirm that you want to update this order.'),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(null),
              child: const Text('Cancel'),
            ),
            FilledButton(
                onPressed: () => Navigator.of(dialogContext).pop(nextStatuses.first),
                child: Text(nextStatuses.first == 'completed' ? 'Confirm Delivery' : 'Update Status'),
            ),
          ],
        ),
      );

        if (selectedStatus == null) {
        if (mounted) {
          setState(() => isProcessing = false);
          await cameraController.start();
        }
        return;
      }

      final result = await api.updateDeliveryStatus(
        orderId: int.tryParse(previewData['order_id'].toString()) ?? 0,
        deliveryId: int.tryParse(previewData['delivery_id'].toString()) ?? 0,
        status: selectedStatus,
        qrCode: qrCodeData,
        token: widget.token,
      );

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content:
              Text(result['message'] ?? 'Delivery confirmed successfully.'),
          backgroundColor: Colors.green,
        ),
      );
      if (widget.returnToCaller) {
        Navigator.of(context).pop(true);
      } else {
        setState(() => isProcessing = false);
        await cameraController.start();
      }
    } catch (e) {
      var errorMessage = e.toString().replaceAll('Exception: ', '');
      if (e is DioException) {
        errorMessage = e.error?.toString() ?? 'Server update failed';
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage), backgroundColor: Colors.red),
      );
      setState(() => isProcessing = false);
      await cameraController.start();
    }
  }

  @override
  void dispose() {
    cameraController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Scan Parcel QR'),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        elevation: 0,
      ),
      backgroundColor: const Color(0xFF081A2A),
      body: Stack(
        children: [
          Positioned.fill(
            child: MobileScanner(
            controller: cameraController,
            onDetect: (capture) {
              if (isProcessing) return;
              for (final barcode in capture.barcodes) {
                final rawValue = barcode.rawValue;
                if (rawValue != null && rawValue.trim().isNotEmpty) {
                  _handleScan(rawValue.trim());
                  break;
                }
              }
            },
            ),
          ),
          Center(
            child: LayoutBuilder(
              builder: (context, constraints) {
                final size = (constraints.maxWidth * 0.72).clamp(220.0, 310.0);
                return Container(
                  width: size,
                  height: size,
                  decoration: BoxDecoration(
                    border: Border.all(color: AppColors.accent, width: 3),
                    borderRadius: BorderRadius.circular(24),
                    boxShadow: const [BoxShadow(color: Colors.black45, blurRadius: 18)],
                  ),
                );
              },
            ),
          ),
          Positioned(
            left: 24,
            right: 24,
            bottom: 28,
            child: IgnorePointer(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
                decoration: BoxDecoration(
                  color: Colors.black54,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: const Text(
                  'Align the parcel QR code inside the frame',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                ),
              ),
            ),
          ),
          if (isProcessing)
            Container(
              color: Colors.black54,
              child: const Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    CircularProgressIndicator(color: Colors.white),
                    SizedBox(height: 16),
                    Text(
                      'Checking scanned order...',
                      style: TextStyle(
                          color: Colors.white,
                          fontSize: 16,
                          fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}
