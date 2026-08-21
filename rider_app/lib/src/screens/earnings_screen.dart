import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';

/// Phase 2 — Earning and Performance Dashboard.
///
/// Shows the rider's completed-delivery earnings, tips, delivery fees,
/// remittance history, and a simple weekly/monthly earnings bar chart.
class EarningsScreen extends StatefulWidget {
  final String token;
  const EarningsScreen({super.key, required this.token});

  @override
  State<EarningsScreen> createState() => _EarningsScreenState();
}

class _EarningsScreenState extends State<EarningsScreen> {
  late Future<Map<String, dynamic>> _earningsFuture;
  late final ApiClient _apiClient;
String _chartPeriod = 'monthly';
  Map<String, dynamic> _chart = {};

  @override
  void initState() {
    super.initState();
    _apiClient = ApiClient(baseUrl: baseUrl);
    _earningsFuture = _fetchEarnings();
  }

  Future<Map<String, dynamic>> _fetchEarnings() async {
    try {
      final data = await _apiClient.getEarnings(token: widget.token);
      if (mounted) {
        _chart = Map<String, dynamic>.from(data['chart'] ?? {});
      }
      return data;
    } catch (e) {
      throw Exception(e.toString().replaceAll('Exception: ', ''));
    }
  }

  Future<void> _refresh() async {
    setState(() {
      _earningsFuture = _fetchEarnings();
    });
  }

  /// Loads chart data for the selected period (weekly/monthly).
  Future<void> _loadChart(String period) async {
    setState(() {
      _chartPeriod = period;
      _chart = {}; // show a lightweight loading state for the chart
    });
    try {
      final chart = await _apiClient.getEarningsChart(
        period: period,
        token: widget.token,
      );
      if (mounted) {
        setState(() {
          _chart = chart;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _chart = {};
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final currencyFormat = NumberFormat.currency(locale: 'en_PH', symbol: '₱');
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        elevation: 0,
        backgroundColor: Colors.transparent,
        title: const Text(
          'Earnings Dashboard',
          style: TextStyle(color: Colors.black, fontWeight: FontWeight.bold, fontSize: 22),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.black),
            onPressed: _refresh,
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<Map<String, dynamic>>(
          future: _earningsFuture,
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
                      const Icon(Icons.error_outline, color: Colors.red, size: 48),
                      const SizedBox(height: 12),
                      Text(
                        'Failed to load earnings data.',
                        textAlign: TextAlign.center,
                        style: TextStyle(color: Colors.grey.shade700),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        '${snapshot.error}',
                        textAlign: TextAlign.center,
                        style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
                      ),
                      const SizedBox(height: 16),
                      ElevatedButton(onPressed: _refresh, child: const Text('Retry')),
                    ],
                  ),
                ),
              );
            }

            final data = snapshot.data ?? {};
            final summary = Map<String, dynamic>.from(data['summary'] ?? {});
            final earningsHistory = List<Map<String, dynamic>>.from(
              (data['earnings_history'] as List?)?.map((e) => Map<String, dynamic>.from(e)) ?? [],
            );
            final remittanceHistory = List<Map<String, dynamic>>.from(
              (data['remittance_history'] as List?)?.map((e) => Map<String, dynamic>.from(e)) ?? [],
            );

            final totalEarnings = _asDouble(summary['total_earnings']);
            final totalDeliveries = _asInt(summary['total_deliveries']);
            final totalTips = _asDouble(summary['total_tips']);
            final totalFees = _asDouble(summary['total_delivery_fees']);

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              children: [
                // --- Hero card: Total Earnings ---
                Container(
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
                        color: AppColors.primary.withValues(alpha: 0.3),
                        blurRadius: 16,
                        offset: const Offset(0, 6),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Total Earnings',
                        style: TextStyle(color: Colors.white70, fontSize: 14),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        currencyFormat.format(totalEarnings),
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 34,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        '$totalDeliveries completed deliveries',
                        style: const TextStyle(color: Colors.white70, fontSize: 13),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // --- Metric cards ---
                GridView.count(
                  crossAxisCount: 2,
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  crossAxisSpacing: 10,
                  mainAxisSpacing: 10,
                  childAspectRatio: 1.5,
                  children: [
                    _buildMetricCard(
                      'Deliveries',
                      '$totalDeliveries',
                      'Completed',
                      AppColors.primary,
                      Icons.local_shipping_outlined,
                    ),
                    _buildMetricCard(
                      'Tips',
                      currencyFormat.format(totalTips),
                      'From customers',
                      AppColors.accent,
                      Icons.volunteer_activism_outlined,
                    ),
                    _buildMetricCard(
                      'Delivery Fees',
                      currencyFormat.format(totalFees),
                      'Earned',
                      AppColors.statusDeliveredText,
                      Icons.payments_outlined,
                    ),
                    _buildMetricCard(
                      'Avg / Delivery',
                      currencyFormat.format(totalDeliveries == 0 ? 0 : totalEarnings / totalDeliveries),
                      'Per completion',
                      Colors.deepPurple,
                      Icons.trending_up,
                    ),
                  ],
                ),
                const SizedBox(height: 20),

                // --- Chart section ---
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: AppColors.cardBg,
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
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text(
                            'Earnings Trend',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                          ),
                          _buildPeriodToggle(),
                        ],
                      ),
                      const SizedBox(height: 16),
                      SizedBox(
                        height: 180,
                        child: _chart.isEmpty
                            ? const Center(child: CircularProgressIndicator())
                            : _EarningsBarChart(
                                labels: List<String>.from(_chart['labels'] ?? []),
                                values: (List.from(_chart['values'] ?? [])).map((v) => _asDouble(v)).toList(),
                              ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 24),

                // --- Earnings history ---
                Row(
                  children: [
                    const Text(
                      'Earnings History',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade300,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        '${earningsHistory.length}',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                if (earningsHistory.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 20),
                    child: Center(child: Text('No completed deliveries yet.')),
                  )
                else
                  ...earningsHistory.map((e) => _buildEarningsEntry(e, currencyFormat)),
                const SizedBox(height: 24),

                // --- Remittance history ---
                Row(
                  children: [
                    const Text(
                      'Remittance History',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade300,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        '${remittanceHistory.length}',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                if (remittanceHistory.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 20),
                    child: Center(child: Text('No remittance history yet.')),
                  )
                else
                  ...remittanceHistory.map((r) => _buildRemittanceEntry(r, currencyFormat)),
                const SizedBox(height: 24),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _buildPeriodToggle() {
    return Row(
      children: ['weekly', 'monthly'].map((p) {
        final isSelected = _chartPeriod == p;
        return GestureDetector(
          onTap: () => _loadChart(p),
          child: Container(
            margin: const EdgeInsets.only(left: 8),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
            decoration: BoxDecoration(
              color: isSelected ? AppColors.primary : AppColors.cardBg,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: isSelected ? AppColors.primary : Colors.grey.shade300),
            ),
            child: Text(
              p[0].toUpperCase() + p.substring(1),
              style: TextStyle(
                color: isSelected ? Colors.white : Colors.black87,
                fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                fontSize: 12,
              ),
            ),
          ),
        );
      }).toList(),
    );
  }

  Widget _buildMetricCard(String title, String value, String subtext, Color color, IconData icon) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade200),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.02),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Row(
            children: [
              Icon(icon, size: 16, color: color),
              const SizedBox(width: 6),
              Text(title, style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            ],
          ),
          const SizedBox(height: 6),
          Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
          const SizedBox(height: 4),
          Text(subtext, style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w500)),
        ],
      ),
    );
  }

  Widget _buildEarningsEntry(Map<String, dynamic> e, NumberFormat fmt) {
    final orderNumber = e['order_number'] ?? 'N/A';
    final earnings = _asDouble(e['earnings']);
    final tip = _asDouble(e['tip']);
    final fee = _asDouble(e['delivery_fee']);
    final createdAt = DateTime.tryParse(e['created_at']?.toString() ?? '');

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.grey.shade200),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.02),
            blurRadius: 8,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: AppColors.primarySoft,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.check_circle_outline, color: AppColors.primary, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Order #$orderNumber',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                ),
                const SizedBox(height: 4),
                Text(
                  '${fmt.format(fee)} fee · ${fmt.format(tip)} tip',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                ),
                const SizedBox(height: 2),
                Text(
                  createdAt != null ? DateFormat('MMM d, yyyy').format(createdAt) : '',
                  style: TextStyle(color: Colors.grey.shade400, fontSize: 11),
                ),
              ],
            ),
          ),
          Text(
            fmt.format(earnings),
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: AppColors.primary),
          ),
        ],
      ),
    );
  }

  Widget _buildRemittanceEntry(Map<String, dynamic> r, NumberFormat fmt) {
    final amount = _asDouble(r['amount']);
    final status = (r['status'] ?? 'pending').toString().toUpperCase();
    final requestedAt = DateTime.tryParse(r['requested_at']?.toString() ?? '');

    final Color statusColor;
    switch (status.toLowerCase()) {
      case 'approved':
        statusColor = AppColors.statusDeliveredText;
        break;
      case 'rejected':
        statusColor = AppColors.statusDangerText;
        break;
      default:
        statusColor = AppColors.accent;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.grey.shade200),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.02),
            blurRadius: 8,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: statusColor.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              status,
              style: TextStyle(color: statusColor, fontSize: 11, fontWeight: FontWeight.bold),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  requestedAt != null ? DateFormat('MMM d, yyyy').format(requestedAt) : '',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                ),
                if ((r['notes'] ?? '').toString().isNotEmpty)
                  Text(
                    r['notes'].toString(),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(color: Colors.grey.shade400, fontSize: 11),
                  ),
              ],
            ),
          ),
          Text(
            fmt.format(amount),
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
          ),
        ],
      ),
    );
  }

  static double _asDouble(dynamic v) => double.tryParse(v?.toString() ?? '') ?? 0.0;
  static int _asInt(dynamic v) => int.tryParse(v?.toString() ?? '') ?? 0;
}

/// A simple, dependency-free bar chart drawn with [CustomPainter].
class _EarningsBarChart extends StatelessWidget {
  final List<String> labels;
  final List<double> values;
  const _EarningsBarChart({required this.labels, required this.values});

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      size: Size.infinite,
      painter: _BarChartPainter(labels: labels, values: values),
    );
  }
}

class _BarChartPainter extends CustomPainter {
  final List<String> labels;
  final List<double> values;
  _BarChartPainter({required this.labels, required this.values});

  @override
  void paint(Canvas canvas, Size size) {
    if (labels.isEmpty) return;

    final maxValue = values.isEmpty
        ? 0.0
        : values.reduce((a, b) => a > b ? a : b);
    final safeMax = maxValue <= 0 ? 1.0 : maxValue;

    final chartBottom = size.height - 24; // leave room for labels
    const chartTop = 8.0;
    final chartHeight = chartBottom - chartTop;

    final slotWidth = size.width / labels.length;
    final barWidth = slotWidth * 0.5;

    // Grid lines
    final gridPaint = Paint()
      ..color = Colors.grey.shade200
      ..strokeWidth = 1;
    for (var i = 0; i <= 4; i++) {
      final y = chartBottom - (chartHeight * i / 4);
      canvas.drawLine(Offset(0, y), Offset(size.width, y), gridPaint);
    }

    // Bars
    for (var i = 0; i < labels.length; i++) {
      final value = values.isEmpty ? 0.0 : values[i];
      final barHeight = chartHeight * (value / safeMax).clamp(0.0, 1.0);
      final centerX = slotWidth * i + slotWidth / 2;
      final left = centerX - barWidth / 2;
      final top = chartBottom - barHeight;

      final barPaint = Paint()
        ..shader = const LinearGradient(
          begin: Alignment.bottomCenter,
          end: Alignment.topCenter,
          colors: [AppColors.primary, Color(0xFF00B8C4)],
        ).createShader(Rect.fromLTWH(left, top, barWidth, barHeight));

      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(left, top, barWidth, barHeight),
          const Radius.circular(6),
        ),
        barPaint,
      );

      // Value label above bar
      if (value > 0) {
        final textPainter = TextPainter(
          text: TextSpan(
            text: value.toStringAsFixed(0),
            style: const TextStyle(color: AppColors.textMain, fontSize: 9, fontWeight: FontWeight.bold),
          ),
textDirection: ui.TextDirection.ltr,
        )..layout();
        textPainter.paint(
          canvas,
          Offset(centerX - textPainter.width / 2, top - 14),
        );
      }

      // X label
      final labelPainter = TextPainter(
        text: TextSpan(
          text: labels[i],
          style: TextStyle(color: Colors.grey.shade600, fontSize: 10),
        ),
textDirection: ui.TextDirection.ltr,
      )..layout();
      labelPainter.paint(
        canvas,
        Offset(centerX - labelPainter.width / 2, chartBottom + 6),
      );
    }
  }

  @override
  bool shouldRepaint(covariant _BarChartPainter oldDelegate) {
    return oldDelegate.labels != labels || oldDelegate.values != values;
  }
}
