import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../constants/colors.dart';

/// Ticket Center screen.
///
/// A support ticket form with an in-session list of submitted tickets. Since
/// there is no backend for tickets yet, tickets are tracked in memory for the
/// current session.
class TicketCenterScreen extends StatefulWidget {
  const TicketCenterScreen({super.key});

  @override
  State<TicketCenterScreen> createState() => _TicketCenterScreenState();
}

class _TicketCenterScreenState extends State<TicketCenterScreen> {
  final List<_Ticket> _tickets = [];
  final _subjectController = TextEditingController();
  final _messageController = TextEditingController();

  @override
  void dispose() {
    _subjectController.dispose();
    _messageController.dispose();
    super.dispose();
  }

  Future<void> _openNewTicket() async {
    final submitted = await showDialog<bool>(
      context: context,
      builder: (context) => _NewTicketDialog(
        subjectController: _subjectController,
        messageController: _messageController,
      ),
    );

    if (submitted == true && mounted) {
      final subject = _subjectController.text.trim();
      final message = _messageController.text.trim();
      if (subject.isNotEmpty && message.isNotEmpty) {
        setState(() {
          _tickets.insert(
            0,
            _Ticket(
              id: 'TCK-${1000 + _tickets.length + 1}',
              subject: subject,
              message: message,
              status: 'Open',
              createdAt: DateTime.now(),
            ),
          );
        });
        _subjectController.clear();
        _messageController.clear();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Ticket Center',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
      ),
      body: _tickets.isEmpty ? _buildEmpty() : _buildList(),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openNewTicket,
        icon: const Icon(Icons.add),
        label: const Text('New Ticket'),
        backgroundColor: AppColors.primary,
      ),
    );
  }

  Widget _buildEmpty() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.confirmation_number_outlined, size: 56, color: AppColors.textMuted),
            const SizedBox(height: 12),
            const Text(
              'No support tickets yet.\nTap "New Ticket" to report an issue.',
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.textMuted),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildList() {
    return RefreshIndicator(
      onRefresh: () async {},
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _tickets.length,
        itemBuilder: (context, index) {
          final t = _tickets[index];
          return Container(
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.cardBg,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Colors.grey.shade200),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${t.id} · ${t.subject}',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.textMain),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppColors.primarySoftBg,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        t.status,
                        style: const TextStyle(color: AppColors.primary, fontSize: 11, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  t.message,
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 13, height: 1.4),
                ),
                const SizedBox(height: 8),
                Text(
                  DateFormat('MMM d, yyyy h:mm a').format(t.createdAt),
                  style: TextStyle(color: Colors.grey.shade500, fontSize: 11),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _Ticket {
  final String id;
  final String subject;
  final String message;
  final String status;
  final DateTime createdAt;
  _Ticket({
    required this.id,
    required this.subject,
    required this.message,
    required this.status,
    required this.createdAt,
  });
}

class _NewTicketDialog extends StatelessWidget {
  final TextEditingController subjectController;
  final TextEditingController messageController;
  const _NewTicketDialog({required this.subjectController, required this.messageController});

  @override
  Widget build(BuildContext context) {
    final formKey = GlobalKey<FormState>();
    return AlertDialog(
      title: const Text('New Support Ticket'),
      content: Form(
        key: formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextFormField(
              controller: subjectController,
              decoration: const InputDecoration(
                labelText: 'Subject',
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Subject is required' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: messageController,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'Describe your issue',
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Please describe your issue' : null,
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        ElevatedButton(
          onPressed: () {
            if (formKey.currentState!.validate()) {
              Navigator.of(context).pop(true);
            }
          },
          child: const Text('Submit'),
        ),
      ],
    );
  }
}
