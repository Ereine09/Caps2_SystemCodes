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
  static const _primary = Color(0xFF4F46E5);
  static const _background = Color(0xFFF8FAFC);
  static const _mainText = Color(0xFF1E293B);
  static const _secondaryText = Color(0xFF64748B);

  final _scannerController = MobileScannerController();
  final _api = ApiService();
  Order? _order;
  String? _qrData;
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
        _qrData = rawValue.trim();
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
    if (order.status == _selectedStatus) {
      _showMessage('Order is already ${_label(_selectedStatus)}.');
      return;
    }
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Update Order Status?'),
        content: Text('Order #${order.orderNumber}\n\nCurrent Status: ${_label(order.status)}\nNew Status: ${_label(_selectedStatus)}'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Update Status')),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() {
      _updating = true;
      _message = null;
    });
    try {
      final result = await _api.updateOrderStatus(orderId: order.id, status: _selectedStatus, token: token);
      final refreshedOrder = _qrData == null ? null : await _api.lookupOrder(_qrData!, token);
      final emailSent = result['email_sent'] == true;
      if (mounted) {
        setState(() {
          _order = refreshedOrder ?? order.withStatus(_selectedStatus);
            _message = emailSent
              ? 'Order status updated successfully.\nCustomer notification sent.'
              : 'Order status updated.\nEmail notification could not be sent.';
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
      _qrData = null;
      _message = null;
    });
    await _scannerController.start();
  }

  String _label(String status) => const {
        'ready_for_pickup': 'Ready Pickup',
        'out_for_delivery': 'Out Delivery',
      }[status] ?? status.replaceAll('_', ' ').split(' ').map((word) => word.isEmpty ? word : '${word[0].toUpperCase()}${word.substring(1)}').join(' ');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _background,
      appBar: AppBar(
        backgroundColor: Colors.white,
        title: const Text('Order scanner', style: TextStyle(color: _mainText, fontWeight: FontWeight.w700)),
        actions: [IconButton(onPressed: () => context.read<AuthState>().logout(), icon: const Icon(Icons.logout, color: _primary), tooltip: 'Log out')],
      ),
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) => Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
              child: ConstrainedBox(
                constraints: BoxConstraints(
                  maxWidth: 420,
                  minHeight: constraints.maxHeight - 36,
                ),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _scannerView(),
                    const SizedBox(height: 24),
                    if (_loadingOrder)
                      const Center(child: CircularProgressIndicator()),
                    if (_order != null) _orderSection(_order!),
                    const SizedBox(height: 16),
                    _statusPicker(),
                    if (_order != null) ...[
                      const SizedBox(height: 14),
                      _updateButton(_order!),
                    ],
                    if (_message != null) ...[
                      const SizedBox(height: 14),
                      Text(_message!, textAlign: TextAlign.center, style: TextStyle(color: _messageIsError ? const Color(0xFFB42318) : const Color(0xFF16704A))),
                    ],
                    if (_order != null) ...[
                      const SizedBox(height: 18),
                      Center(child: TextButton.icon(style: TextButton.styleFrom(foregroundColor: _primary), onPressed: _scanAnother, icon: const Icon(Icons.qr_code_scanner), label: const Text('Scan another order'))),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _scannerView() {
    return Center(
      child: LayoutBuilder(
        builder: (context, constraints) {
          final size = (constraints.maxWidth - 20).clamp(220.0, 360.0);
          return SizedBox(
            width: size + 20,
            height: size + 20,
            child: Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: const Color(0xFFC7D2FE)),
                boxShadow: const [BoxShadow(color: Color(0x140F172A), blurRadius: 18, offset: Offset(0, 8))],
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    MobileScanner(controller: _scannerController, onDetect: _handleScan),
                    IgnorePointer(child: CustomPaint(painter: _ScannerCornersPainter())),
                    if (_loadingOrder) Container(color: Colors.white.withValues(alpha: 0.75)),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _statusPicker() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: const Color(0xFFC7D2FE), width: 1.2), boxShadow: const [BoxShadow(color: Color(0x100F172A), blurRadius: 12, offset: Offset(0, 4))]),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          isExpanded: true,
          value: _selectedStatus,
          icon: const Icon(Icons.keyboard_arrow_down, color: _primary),
          style: const TextStyle(color: _mainText, fontWeight: FontWeight.w600),
          onChanged: (value) {
            if (value != null) setState(() => _selectedStatus = value);
          },
          items: Order.allowedStatuses.map((status) => DropdownMenuItem(value: status, child: Text(_label(status)))).toList(),
        ),
      ),
    );
  }

  Widget _orderSection(Order order) {
    return Column(
      children: [
        Container(
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 14),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: const Color(0xFFE2E8F0)), boxShadow: const [BoxShadow(color: Color(0x100F172A), blurRadius: 14, offset: Offset(0, 5))]),
          child: Column(
            children: [
              const Text('Order found', style: TextStyle(color: _primary, fontSize: 13, fontWeight: FontWeight.w700)),
              const SizedBox(height: 7),
              Text('Order #${order.orderNumber}', textAlign: TextAlign.center, style: const TextStyle(color: _mainText, fontSize: 18, fontWeight: FontWeight.w700)),
        if (order.customerName != null) ...[const SizedBox(height: 4), Text(order.customerName!, style: const TextStyle(color: Colors.black54))],
              const SizedBox(height: 14),
              Row(mainAxisAlignment: MainAxisAlignment.center, children: [const Text('Current status', style: TextStyle(color: _secondaryText)), const SizedBox(width: 8), _statusBadge(order.status)]),
            ],
          ),
        ),
      ],
    );
  }

  Widget _statusBadge(String status) {
    final color = status == 'completed' ? const Color(0xFF10B981) : status == 'cancelled' ? const Color(0xFFEF4444) : _primary;
    return Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5), decoration: BoxDecoration(color: color.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(20)), child: Text(_label(status), style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700)));
  }

  Widget _updateButton(Order order) {
    final terminal = order.status == 'completed' || order.status == 'cancelled';
    return ElevatedButton(
      style: ElevatedButton.styleFrom(backgroundColor: _primary, foregroundColor: Colors.white, minimumSize: const Size.fromHeight(50), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
      onPressed: terminal || _updating ? null : _updateStatus,
      child: _updating
          ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
          : const Text('Update status'),
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
