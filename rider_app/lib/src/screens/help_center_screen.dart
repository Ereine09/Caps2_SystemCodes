import 'package:flutter/material.dart';
import '../constants/colors.dart';

/// Help Center screen.
///
/// FAQ accordion + support/contact info. Fully self-contained (no backend).
class HelpCenterScreen extends StatefulWidget {
  const HelpCenterScreen({super.key});

  @override
  State<HelpCenterScreen> createState() => _HelpCenterScreenState();
}

class _HelpCenterScreenState extends State<HelpCenterScreen> {
  final Set<int> _expanded = {};

  static const List<({String q, String a})> _faqs = [
    (
      q: 'How do I go online?',
      a: 'Open the Delivery dashboard and tap the Online/Offline toggle at the top right of the header. When online, you can start receiving delivery assignments.',
    ),
    (
      q: 'How do I accept a delivery?',
      a: 'Your assigned parcels appear on the Delivery dashboard. Tap a parcel card to see the full details, then use the Call, Navigate, or QR confirm actions as needed.',
    ),
    (
      q: 'How do I confirm a delivery with a QR code?',
      a: 'When an order is out for delivery, open its details and tap "Confirm with QR Code". Scan the customer\'s QR to finalize the handover.',
    ),
    (
      q: 'Where do I upload proof of delivery?',
      a: 'Upload a proof photo from the order details screen (if out for delivery) or from the "Upload ePOD" section in the drawer menu.',
    ),
    (
      q: 'How do I view my earnings?',
      a: 'Open the Earnings dashboard from the bottom navigation bar or the Performance item in the drawer. You\'ll see fees, tips, and remittance history.',
    ),
    (
      q: 'How do I request a remittance?',
      a: 'Open the Remittance tab in the bottom navigation. You\'ll see your cash on hand and a button to submit a remittance request to the admin.',
    ),
    (
      q: 'How do I contact support?',
      a: 'If you still need help, you can message the customer for order issues, or reach our support line at the contact details below.',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Help Center',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [AppColors.primary, Color(0xFF006064)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(20),
            ),
            child: const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Need help?',
                  style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold),
                ),
                SizedBox(height: 6),
                Text(
                  'Browse common questions below or contact our support team.',
                  style: TextStyle(color: Colors.white70, fontSize: 14),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          const Text(
            'Frequently Asked Questions',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.textMain),
          ),
          const SizedBox(height: 8),
          ..._faqs.asMap().entries.map((entry) {
            final idx = entry.key;
            final faq = entry.value;
            final isExpanded = _expanded.contains(idx);
            return Container(
              margin: const EdgeInsets.only(bottom: 10),
              decoration: BoxDecoration(
                color: AppColors.cardBg,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: Colors.grey.shade200),
              ),
              child: ExpansionTile(
                key: PageStorageKey(idx),
                tilePadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
                leading: Icon(Icons.help_outline, color: AppColors.primary, size: 22),
                title: Text(
                  faq.q,
                  style: const TextStyle(fontWeight: FontWeight.w600, color: AppColors.textMain, fontSize: 14),
                ),
                initiallyExpanded: isExpanded,
                onExpansionChanged: (val) {
                  setState(() {
                    if (val) {
                      _expanded.add(idx);
                    } else {
                      _expanded.remove(idx);
                    }
                  });
                },
                children: [
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      faq.a,
                      style: TextStyle(color: Colors.grey.shade700, fontSize: 13, height: 1.5),
                    ),
                  ),
                ],
              ),
            );
          }),
          const SizedBox(height: 12),
          const Text(
            'Contact Support',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.textMain),
          ),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.cardBg,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Colors.grey.shade200),
            ),
            child: const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _ContactRow(icon: Icons.phone_outlined, text: '+63 123 456 7890'),
                SizedBox(height: 12),
                _ContactRow(icon: Icons.email_outlined, text: 'support@riderapp.ph'),
                SizedBox(height: 12),
                _ContactRow(icon: Icons.chat_outlined, text: 'Live chat: Mon–Sat, 8am–6pm'),
              ],
            ),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}

class _ContactRow extends StatelessWidget {
  final IconData icon;
  final String text;
  const _ContactRow({required this.icon, required this.text});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, color: AppColors.primary, size: 20),
        const SizedBox(width: 12),
        Expanded(child: Text(text, style: const TextStyle(color: AppColors.textMain, fontSize: 14))),
      ],
    );
  }
}
