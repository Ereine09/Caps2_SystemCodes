import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import '../models/order.dart';
import '../services/api_service.dart';
import '../state/auth_state.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final _scannerController = MobileScannerController();
  final _api = ApiService();
  Order? _order;
  String _selectedStatus = 'processing';
  bool _loadingOrder = false;
  bool _updating = false;
  String? _message;
  bool _messageIsError = false;

  @override
  void dispose() {
    _scannerController.dispose();
    super.dispose();
  }

  Future<void> _handleScan(BarcodeCapture capture) async {
    if (_loadingOrder || _updating) return;
    final rawValue = capture.barcodes.map((barcode) => barcode.rawValue).whereType<String>().firstWhere(
          (value) => value.trim().isNotEmpty,
          orElse: () => '',
        );
    if (rawValue.isEmpty) return;
    try {
      final decoded = jsonDecode(rawValue);
      if (decoded is! Map || decoded['delivery_id'] == null || decoded['order_id'] == null || decoded['token'] == null) {
        throw const FormatException();
      }
    } catch (_) {
      _showMessage('This is not a valid delivery QR code.', error: true);
      return;
    }

    final authToken = context.read<AuthState>().token;
    if (authToken == null) return;
    setState(() {
      _loadingOrder = true;
      _message = null;
    });
    await _scannerController.stop();
    try {
      final order = await _api.lookupOrder(rawValue.trim(), authToken);
      if (!mounted) return;
      setState(() {
        _order = order;
        _selectedStatus = Order.allowedStatuses.contains(order.status) ? order.status : 'processing';
      });
    } catch (error) {
      if (mounted) {
        _showMessage(error.toString().replaceFirst('Exception: ', ''), error: true);
        await _scannerController.start();
      }
    } finally {
      if (mounted) setState(() => _loadingOrder = false);
    }
  }

  void _showMessage(String message, {bool error = false}) {
    if (!mounted) return;
    setState(() {
      _message = message;
      _messageIsError = error;
    });
  }

  Future<void> _updateStatus() async {
    final order = _order;
    final token = context.read<AuthState>().token;
    if (order == null || token == null) return;
    setState(() {
      _updating = true;
      _message = null;
    });
    try {
      await _api.updateOrderStatus(orderId: order.id, status: _selectedStatus, token: token);
      if (mounted) {
        setState(() {
          _order = order.withStatus(_selectedStatus);
          _message = 'Order status updated.';
          _messageIsError = false;
        });
      }
    } catch (error) {
      _showMessage(error.toString().replaceFirst('Exception: ', ''), error: true);
    } finally {
      if (mounted) setState(() => _updating = false);
    }
  }

  Future<void> _scanAnother() async {
    setState(() {
      _order = null;
      _message = null;
    });
    await _scannerController.start();
  }

  String _label(String status) => status.replaceAll('_', ' ').split(' ').map((word) => word.isEmpty ? word : '${word[0].toUpperCase()}${word.substring(1)}').join(' ');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Order scanner', style: TextStyle(fontWeight: FontWeight.w700)),
        actions: [IconButton(onPressed: () => context.read<AuthState>().logout(), icon: const Icon(Icons.logout), tooltip: 'Log out')],
      ),
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) => SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: constraints.maxHeight - 36),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  _scannerView(),
                  const SizedBox(height: 24),
                  if (_loadingOrder) const CircularProgressIndicator(),
                  if (!_loadingOrder && _order == null) _statusPicker(enabled: false),
                  if (_order != null) _orderSection(_order!),
                  if (_message != null) ...[
                    const SizedBox(height: 14),
                    Text(_message!, textAlign: TextAlign.center, style: TextStyle(color: _messageIsError ? const Color(0xFFB42318) : const Color(0xFF16704A))),
                  ],
                  if (_order != null) ...[
                    const SizedBox(height: 18),
                    TextButton.icon(onPressed: _scanAnother, icon: const Icon(Icons.qr_code_scanner), label: const Text('Scan another order')),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _scannerView() {
    return SizedBox(
      width: 286,
      height: 286,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(14),
        child: Stack(
          fit: StackFit.expand,
          children: [
            MobileScanner(controller: _scannerController, onDetect: _handleScan),
            IgnorePointer(child: CustomPaint(painter: _ScannerCornersPainter())),
            if (_loadingOrder) Container(color: Colors.white.withValues(alpha: 0.75)),
          ],
        ),
      ),
    );
  }

  Widget _statusPicker({required bool enabled}) {
    return Container(
      width: 246,
      padding: const EdgeInsets.symmetric(horizontal: 14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(11), border: Border.all(color: const Color(0xFFD8D8D8)), boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 10, offset: Offset(0, 3))]),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          isExpanded: true,
          value: _selectedStatus,
          onChanged: enabled ? (value) => setState(() => _selectedStatus = value!) : null,
          items: Order.allowedStatuses.map((status) => DropdownMenuItem(value: status, child: Text(_label(status)))).toList(),
        ),
      ),
    );
  }

  Widget _orderSection(Order order) {
    final terminal = order.status == 'completed' || order.status == 'cancelled';
    return Column(
      children: [
        Text('Order #${order.orderNumber}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
        if (order.customerName != null) ...[const SizedBox(height: 4), Text(order.customerName!, style: const TextStyle(color: Colors.black54))],
        const SizedBox(height: 16),
        Text('Current status: ${_label(order.status)}', style: const TextStyle(color: Colors.black54)),
        const SizedBox(height: 10),
        _statusPicker(enabled: !terminal),
        const SizedBox(height: 14),
        SizedBox(width: 246, child: ElevatedButton(onPressed: terminal || _updating ? null : _updateStatus, child: _updating ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Text('Update status'))),
      ],
    );
  }
}

class _ScannerCornersPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = Colors.black..strokeWidth = 4..style = PaintingStyle.stroke..strokeCap = StrokeCap.square;
    const length = 34.0;
    const inset = 22.0;
    const left = inset;
    const top = inset;
    final right = size.width - inset;
    final bottom = size.height - inset;
    canvas.drawLine(const Offset(left, top), const Offset(left + length, top), paint);
    canvas.drawLine(const Offset(left, top), const Offset(left, top + length), paint);
    canvas.drawLine(Offset(right, top), Offset(right - length, top), paint);
    canvas.drawLine(Offset(right, top), Offset(right, top + length), paint);
    canvas.drawLine(Offset(left, bottom), Offset(left + length, bottom), paint);
    canvas.drawLine(Offset(left, bottom), Offset(left, bottom - length), paint);
    canvas.drawLine(Offset(right, bottom), Offset(right - length, bottom), paint);
    canvas.drawLine(Offset(right, bottom), Offset(right, bottom - length), paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
