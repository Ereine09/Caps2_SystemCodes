import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';
import 'chat_screen.dart';

/// Conversations list screen — lists customers the rider has (or had) a
/// delivery for, with an unread badge, and opens [ChatScreen] on tap.
class ConversationsScreen extends StatefulWidget {
  final String token;
  const ConversationsScreen({super.key, required this.token});

  @override
  State<ConversationsScreen> createState() => _ConversationsScreenState();
}

class _ConversationsScreenState extends State<ConversationsScreen> {
  late final ApiClient _api;
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _conversations = [];

  @override
  void initState() {
    super.initState();
    _api = ApiClient(baseUrl: baseUrl);
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final items = await _api.getMessagingConversations(token: widget.token);
      if (mounted) {
        setState(() {
          _conversations = items.map((e) => Map<String, dynamic>.from(e as Map)).toList();
          _loading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e.toString().replaceAll('Exception: ', '');
          _loading = false;
        });
      }
    }
  }

  void _openChat(Map<String, dynamic> conv) {
    final customerId = int.tryParse(conv['customer_id']?.toString() ?? '') ?? 0;
    if (customerId <= 0) return;
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ChatScreen(
          customerId: customerId,
          customerName: conv['customer_name']?.toString() ?? 'Customer',
          token: widget.token,
        ),
      ),
    );
  }

  /// Returns the first letter of a name (uppercased), or 'C' if empty.
  String _initial(String name) {
    final trimmed = name.trim();
    if (trimmed.isEmpty) return 'C';
    return trimmed.substring(0, 1).toUpperCase();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Messages',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
        actions: [
          IconButton(icon: const Icon(Icons.refresh, color: Colors.black), onPressed: _load),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.red)),
              const SizedBox(height: 16),
              ElevatedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }
    if (_conversations.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.chat_bubble_outline, size: 56, color: AppColors.textMuted),
              SizedBox(height: 12),
              Text(
                'No conversations yet.\nOnce you complete deliveries, you can chat with customers here.',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppColors.textMuted),
              ),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _conversations.length,
        itemBuilder: (context, index) {
          final conv = _conversations[index];
          final unread = int.tryParse(conv['unread_count']?.toString() ?? '0') ?? 0;
          final lastMessage = conv['last_message']?.toString() ?? '';
          final lastTime = DateTime.tryParse(conv['last_message_time']?.toString() ?? '');

          return Container(
            margin: const EdgeInsets.only(bottom: 12),
            child: ListTile(
              onTap: () => _openChat(conv),
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(14),
                side: BorderSide(color: Colors.grey.shade200),
              ),
              tileColor: AppColors.cardBg,
leading: CircleAvatar(
                backgroundColor: AppColors.primary,
                child: Text(
                  _initial(conv['customer_name']?.toString() ?? 'C'),
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                ),
              ),
              title: Text(
                conv['customer_name']?.toString() ?? 'Customer',
                style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.textMain),
              ),
              subtitle: Text(
                lastMessage.isEmpty ? 'Tap to start chatting' : lastMessage,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: AppColors.textMuted, fontSize: 13),
              ),
              trailing: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  if (lastTime != null)
                    Text(
                      DateFormat('MMM d').format(lastTime),
                      style: const TextStyle(color: AppColors.textMuted, fontSize: 11),
                    ),
                  const SizedBox(height: 6),
                  if (unread > 0)
                    Container(
                      padding: const EdgeInsets.all(5),
                      constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
                      decoration: const BoxDecoration(color: AppColors.statusDangerText, shape: BoxShape.circle),
                      child: Text(
                        '$unread',
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                      ),
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

