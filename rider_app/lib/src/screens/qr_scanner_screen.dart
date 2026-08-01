import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/foundation.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:http/http.dart' as http;

class QRScannerScreen extends StatefulWidget {
  final String token;
  const QRScannerScreen({super.key, required this.token});

  @override
  State<QRScannerScreen> createState() => _QRScannerScreenState();
}

class _QRScannerScreenState extends State<QRScannerScreen> {
  bool isScanning = false;
  bool isProcessing = false;
  String apiResponse = '';
  bool hasError = false;

  // Automatically switch between Localhost (for Web/iOS) and 10.0.2.2 (for Android Emulator)
  final String _baseUrl = kIsWeb 
      ? 'http://localhost/loyalty_managements' 
      : 'http://10.0.2.2/loyalty_managements';

  final MobileScannerController _cameraController = MobileScannerController();

  // --- API Call to your PHP Backend ---
  Future<void> _confirmDelivery(String qrCodeData) async {
    setState(() {
      isProcessing = true;
      apiResponse = '';
      hasError = false;
    });

    try {
      final qrData = jsonDecode(qrCodeData);
      if (qrData['delivery_id'] == null || qrData['token'] == null) {
        throw const FormatException('Invalid QR code content.');
      }

      final response = await http.post(
        Uri.parse('$_baseUrl/modules/rider/rider_qr_confirm_api.php'),
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ${widget.token}',
        },
        body: jsonEncode({
          'delivery_id': qrData['delivery_id'],
          'qr_code': qrCodeData,
        }),
      );

      final responseData = jsonDecode(response.body);

      if (response.statusCode == 200 && responseData['success'] == true) {
        setState(() {
          apiResponse = 'Success! Order #${responseData['data']['order_id']} Completed.';
          hasError = false;
        });
      } else {
        setState(() {
          apiResponse = 'Error: ${responseData['message'] ?? 'Unknown API error.'}';
          hasError = true;
        });
      }
    } catch (e) {
      setState(() {
        apiResponse = 'An error occurred: ${e.toString()}';
        hasError = true;
      });
    } finally {
      setState(() {
        isProcessing = false;
        isScanning = false; // Stop scanning view after processing
        _cameraController.stop();
      });
    }
  }

  void _resetScanner() {
    setState(() {
      isScanning = true;
      isProcessing = false;
      apiResponse = '';
      hasError = false;
    });
    _cameraController.start();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Confirm Delivery'),
        backgroundColor: const Color(0xFF4a3e94), // Purple color
      ),
      body: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(16.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (!isScanning) ...[
              const Icon(Icons.qr_code_scanner, size: 100, color: Colors.grey),
              const SizedBox(height: 20),
              const Text(
                'Ready to Scan',
                style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 10),
              const Text(
                'Press the camera button to start scanning the customer\'s QR code.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 16, color: Colors.black54),
              ),
              const SizedBox(height: 30),
            ],
            if (apiResponse.isNotEmpty)
              _buildResponseWidget(),
            if (isScanning)
              Expanded(child: _buildScannerWidget()),
          ],
        ),
      ),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerFloat,
      floatingActionButton: FloatingActionButton.extended(
        onPressed: isProcessing ? null : (isScanning ? () => setState(() => isScanning = false) : _resetScanner),
        backgroundColor: isScanning ? Colors.redAccent : const Color(0xFF4a3e94),
        icon: Icon(isScanning ? Icons.stop : Icons.camera_alt),
        label: Text(isScanning ? 'Stop' : 'Start Scan'),
      ),
    );
  }

  Widget _buildScannerWidget() {
    return ClipRRect(
      borderRadius: BorderRadius.circular(20),
      child: Stack(
        children: [
          MobileScanner(
            controller: _cameraController,
            onDetect: (capture) {
              if (isProcessing) return;
              final List<Barcode> barcodes = capture.barcodes;
              if (barcodes.isNotEmpty) {
                final String code = barcodes.first.rawValue ?? "";
                if (code.isNotEmpty) {
                  _confirmDelivery(code);
                }
              }
            },
          ),
          if (isProcessing)
            Container(
              color: Colors.black54,
              child: const Center(child: CircularProgressIndicator(color: Colors.white)),
            ),
        ],
      ),
    );
  }

  Widget _buildResponseWidget() {
    return Container(
      margin: const EdgeInsets.only(top: 20, bottom: 20),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: hasError ? Colors.red.shade100 : Colors.green.shade100,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: hasError ? Colors.red.shade300 : Colors.green.shade300),
      ),
      child: Row(
        children: [
          Icon(hasError ? Icons.error_outline : Icons.check_circle_outline, color: hasError ? Colors.red : Colors.green, size: 32),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              apiResponse,
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: hasError ? Colors.red.shade900 : Colors.green.shade900),
            ),
          ),
        ],
      ),
    );
  }
}
