import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';

class QRScannerScreen extends StatefulWidget {
  final String token;
  const QRScannerScreen({super.key, required this.token});

  @override
  State<QRScannerScreen> createState() => _QRScannerScreenState();
}

class _QRScannerScreenState extends State<QRScannerScreen> {
  bool _isProcessing = false;
  final MobileScannerController _cameraController = MobileScannerController();

  final String _baseUrl = baseUrl;

  Future<void> _updateOrderStatus(String orderNumber) async {
    setState(() => _isProcessing = true);
    _cameraController.stop(); // I-pause muna ang camera para hindi mag-scan nang paulit-ulit

    try {
      final api = ApiClient(baseUrl: _baseUrl);
      final res = await api.postJson<Map<String, dynamic>>(
        '/modules/rider/rider_complete_order_api.php',
        body: {
          'order_number': orderNumber,
        },
        headers: {
          'Authorization': 'Bearer ${widget.token}',
        },
      );

      final body = res.data ?? {};
      if (body['success'] != true) {
        throw Exception(body['message'] ?? 'Failed to complete order');
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(body['message'] ?? 'Order marked as Completed!'), backgroundColor: Colors.green),
      );
      Navigator.of(context).pop(); // Bumalik sa Dashboard pagkatapos mag-success
    } catch (e) {
      String errMsg = e.toString().replaceAll('Exception: ', '');
      if (e is DioException) {
        errMsg = e.error?.toString() ?? 'Server update failed';
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errMsg), backgroundColor: Colors.red),
      );
      
      // Kung nag-error, buhayin ulit ang camera para makapag-scan uli
      setState(() => _isProcessing = false);
      _cameraController.start();
    }
  }

  @override
  void dispose() {
    _cameraController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Scan Customer E-Receipt')),
      body: Stack(
        children: [
          MobileScanner(
            controller: _cameraController,
            onDetect: (capture) {
              if (_isProcessing) return;
              
              final List<Barcode> barcodes = capture.barcodes;
              for (final barcode in barcodes) {
                final String? rawValue = barcode.rawValue;
                if (rawValue != null && rawValue.isNotEmpty) {
                  _updateOrderStatus(rawValue.trim());
                  break;
                }
              }
            },
          ),
          // Custom scanner overlay outline
          Center(
            child: Container(
              width: 250,
              height: 250,
              decoration: BoxDecoration(
                border: Border.all(color: Colors.red, width: 3),
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          ),
          if (_isProcessing)
            Container(
              color: Colors.black54,
              child: const Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    CircularProgressIndicator(color: Colors.white),
                    SizedBox(height: 16),
                    Text('Updating order status...', style: TextStyle(color: Colors.white, fontSize: 16)),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}