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
      case 'completed':
        return 'Completed';
      case 'cancelled':
        return 'Cancelled';
      default:
        return status.toString().replaceAll('_', ' ');
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

      if (!mounted) return;
      final confirmed = await showDialog<bool>(
        context: context,
        barrierDismissible: false,
        builder: (dialogContext) => AlertDialog(
          title: const Text('Confirm Delivery'),
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
              const Text(
                  'Are you sure you want to mark this order as delivered?'),
              const SizedBox(height: 8),
              const Text(
                  'This action will update the order status to Completed.'),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(dialogContext).pop(true),
              child: const Text('Confirm Delivery'),
            ),
          ],
        ),
      );

      if (confirmed != true) {
        if (mounted) {
          setState(() => isProcessing = false);
          await cameraController.start();
        }
        return;
      }

      final result = await api.verifyDeliveryQR(
        qrCodeData,
        token: widget.token,
        confirm: true,
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
        title: const Text('Scan Customer E-Receipt'),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        elevation: 0,
      ),
      body: Stack(
        children: [
          MobileScanner(
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
          Center(
            child: Container(
              width: 260,
              height: 260,
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.accent, width: 3),
                borderRadius: BorderRadius.circular(20),
                boxShadow: const [
                  BoxShadow(color: Colors.black26, blurRadius: 10)
                ],
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
