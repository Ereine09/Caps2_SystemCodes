import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';

class QRScannerScreen extends StatefulWidget {
  final String token;
  const QRScannerScreen({super.key, required this.token});

  @override
  State<QRScannerScreen> createState() => _QRScannerScreenState();
}

class _QRScannerScreenState extends State<QRScannerScreen> {
  bool isProcessing = false;
  final MobileScannerController cameraController = MobileScannerController();
  final String _baseUrl = baseUrl;

  Future<void> _confirmDelivery(String qrCodeData) async {
    setState(() => isProcessing = true);
    cameraController.stop();

    try {
      final Map<String, dynamic> qrData = jsonDecode(qrCodeData);
      final int? deliveryId = qrData['delivery_id'];

      if (deliveryId == null) {
        throw Exception('Invalid QR code data. Missing delivery_id.');
      }

      final api = ApiClient(baseUrl: _baseUrl);
      final res = await api.postJson<Map<String, dynamic>>(
        '/modules/rider/rider_qr_confirm_api.php',
        body: {
          'delivery_id': deliveryId,
          'qr_code': qrCodeData,
        },
        headers: {
          'Authorization': 'Bearer ${widget.token}',
        },
      );

      final body = res.data ?? {};
      if (body['success'] != true) {
        throw Exception(body['message'] ?? 'Failed to confirm delivery');
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(body['message'] ?? 'Delivery Confirmed!'),
          backgroundColor: Colors.green,
        ),
      );
      Navigator.of(context).pop();
    } catch (e) {
      String errMsg = e.toString().replaceAll('Exception: ', '');
      if (e is DioException) {
        errMsg = e.error?.toString() ?? 'Server update failed';
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errMsg), backgroundColor: Colors.red),
      );
      setState(() => isProcessing = false);
      cameraController.start();
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
              final List<Barcode> barcodes = capture.barcodes;
              for (final barcode in barcodes) {
                final String? rawValue = barcode.rawValue;
                if (rawValue != null && rawValue.isNotEmpty) {
                  _confirmDelivery(rawValue.trim());
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
                      'Confirming Delivery...',
                      style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
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