import 'package:flutter/material.dart';
import '../constants/colors.dart';

class ShipmentCard extends StatelessWidget {
  final String orderNumber;
  final String customerName;
  final String address;
  final String statusLabel;
  final String totalAmount;
  final VoidCallback onTap;

  const ShipmentCard({
    super.key,
    required this.orderNumber,
    required this.customerName,
    required this.address,
    required this.statusLabel,
    required this.totalAmount,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    Color badgeBg = AppColors.statusTransit;
    Color badgeText = AppColors.statusTransitText;
    int stepProgress = 2; 

    final cleanStatus = statusLabel.toLowerCase();
    if (cleanStatus.contains('process') || cleanStatus.contains('pending')) {
      badgeBg = AppColors.statusProcess;
      badgeText = AppColors.statusProcessText;
      stepProgress = 1;
    } else if (cleanStatus.contains('deliver')) {
      badgeBg = AppColors.statusDelivered;
      badgeText = AppColors.statusDeliveredText;
      stepProgress = 4;
    }

    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppColors.cardBg,
          borderRadius: BorderRadius.circular(24),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.02),
              blurRadius: 10,
              offset: const Offset(0, 4),
            )
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  orderNumber,
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: AppColors.textMain),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(color: badgeBg, borderRadius: BorderRadius.circular(20)),
                  child: Text(
                    statusLabel,
                    style: TextStyle(color: badgeText, fontWeight: FontWeight.bold, fontSize: 12),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(customerName, style: const TextStyle(color: AppColors.textMuted, fontSize: 14)),
            const SizedBox(height: 16),
            
            Row(
              children: List.generate(7, (index) {
                if (index % 2 == 0) {
                  int currentDot = (index ~/ 2) + 1;
                  bool isDone = currentDot <= stepProgress;
                  return Container(
                    width: 14, height: 14,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: isDone ? AppColors.accentOrange : Colors.grey[300],
                      border: Border.all(color: Colors.white, width: 2),
                    ),
                  );
                } else {
                  int currentLine = (index ~/ 2) + 1;
                  bool isLineDone = currentLine < stepProgress;
                  return Expanded(
                    child: Container(
                      height: 2,
                      color: isLineDone ? AppColors.accentOrange : Colors.grey[300],
                    ),
                  );
                }
              }),
            ),
            const SizedBox(height: 14),

            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Delivery Address', style: TextStyle(color: AppColors.textMuted, fontSize: 11)),
                      const SizedBox(height: 2),
                      Text(
                        address,
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.textMain),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
                if (totalAmount.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(left: 8.0),
                    child: Text(
                      totalAmount,
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.primary),
                    ),
                  ),
              ],
            )
          ],
        ),
      ),
    );
  }
}