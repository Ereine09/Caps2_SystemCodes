import 'package:flutter/material.dart';

class ApiErrorBanner extends StatelessWidget {
  final String message;

  const ApiErrorBanner({super.key, required this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.red.withValues(alpha: 0.12),
        border: Border.all(color: Colors.red.withValues(alpha: 0.5)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        message,
        style: const TextStyle(color: Colors.red, fontWeight: FontWeight.w600),
      ),
    );
  }
}

