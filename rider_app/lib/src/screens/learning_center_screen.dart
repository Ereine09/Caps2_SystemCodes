import 'package:flutter/material.dart';
import '../constants/colors.dart';

/// Learning Center screen.
///
/// A self-contained educational/onboarding hub with delivery tips, safety
/// guidelines, and app how-to guides. No backend required.
class LearningCenterScreen extends StatelessWidget {
  const LearningCenterScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Learning Center',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _banner(),
          const SizedBox(height: 20),
          const _SectionHeader('Getting Started'),
          const _InfoTile(
            icon: Icons.rocket_launch_outlined,
            title: 'Go Online',
            body: 'Tap the Online toggle on the home screen to start receiving delivery assignments.',
          ),
          const _InfoTile(
            icon: Icons.inventory_2_outlined,
            title: 'Accepting Deliveries',
            body: 'View your parcels on the Delivery dashboard. Tap a card to see full details, call, or navigate to the customer.',
          ),
          const _InfoTile(
            icon: Icons.qr_code_scanner,
            title: 'Confirming with QR',
            body: 'When a parcel is out for delivery, use the QR camera to confirm handover and complete the order.',
          ),
          const SizedBox(height: 20),
          const _SectionHeader('Safety Guidelines'),
          const _InfoTile(
            icon: Icons.health_and_safety_outlined,
            title: 'Your Safety First',
            body: 'Always wear your helmet, follow traffic rules, and keep your vehicle well maintained.',
          ),
          const _InfoTile(
            icon: Icons.verified_user_outlined,
            title: 'Verify the Recipient',
            body: 'Before handing over a parcel, confirm the recipient and ask for a signature or QR scan.',
          ),
          const _InfoTile(
            icon: Icons.call_outlined,
            title: 'Staying in Touch',
            body: 'Use the chat icon to message customers about delivery updates or any issues.',
          ),
          const SizedBox(height: 20),
          const _SectionHeader('Earning More'),
          const _InfoTile(
            icon: Icons.trending_up_rounded,
            title: 'Timely Deliveries',
            body: 'Delivering on time boosts your rating and unlocks more assignments.',
          ),
          const _InfoTile(
            icon: Icons.payments_outlined,
            title: 'Track Your Earnings',
            body: 'Open the Earnings dashboard to see your delivery fees, tips, and remittance history.',
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  Widget _banner() {
    return Container(
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
            'Learn & Grow',
            style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold),
          ),
          SizedBox(height: 6),
          Text(
            'Guides to help you deliver faster and earn more.',
            style: TextStyle(color: Colors.white70, fontSize: 14),
          ),
        ],
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  final String title;
  const _SectionHeader(this.title);

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
      child: Text(
        title,
        style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.textMain),
      ),
    );
  }
}

class _InfoTile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String body;
  const _InfoTile({required this.icon, required this.title, required this.body});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: AppColors.primarySoftBg,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: AppColors.primary, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.textMain)),
                const SizedBox(height: 4),
                Text(body, style: TextStyle(color: Colors.grey.shade700, fontSize: 13, height: 1.4)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
