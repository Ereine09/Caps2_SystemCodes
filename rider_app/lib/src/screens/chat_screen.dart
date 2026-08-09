import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';

/// Rider Chat screen — lets the rider chat with customers using the
/// `rider_messaging_api.php` backend (tbl_messages + WebSocket push).
class ChatScreen extends StatefulWidget {
  final int customerId;
  final String customerName;
  final String token;
  const ChatScreen({
    super.key,
    required this.customerId,
    required this.customerName,
    required this.token,
  });

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  late final ApiClient _api;
  final TextEditingController _messageController = TextEditingController();
  final ScrollController _scrollController = ScrollController();
  bool _loading = true;
  bool _sending = false;
  String? _error;
  List<Map<String, dynamic>> _messages = [];

  @override
  void initState() {
    super.initState();
    _api = ApiClient(baseUrl: baseUrl);
    _load();
  }

  @override
  void dispose() {
    _messageController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final messages = await _api.getMessagingConversation(
        customerId: widget.customerId,
        token: widget.token,
      );
      if (mounted) {
        setState(() {
          _messages = messages.map((e) => Map<String, dynamic>.from(e as Map)).toList();
          _loading = false;
        });
        _scrollToBottom();
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

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollController.hasClients) {
        _scrollController.animateTo(
          _scrollController.position.maxScrollExtent,
          duration: const Duration(milliseconds: 300),
          curve: Curves.easeOut,
        );
      }
    });
  }

  Future<void> _sendMessage() async {
    final text = _messageController.text.trim();
    if (text.isEmpty) return;

    setState(() => _sending = true);
    try {
      await _api.sendRiderMessage(
        customerId: widget.customerId,
        message: text,
        token: widget.token,
      );
      _messageController.clear();
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.backgroundGrey,
      appBar: AppBar(
        backgroundColor: Colors.white,
        elevation: 0.5,
        titleSpacing: 0,
        title: Row(
          children: [
            const CircleAvatar(
              radius: 18,
              backgroundColor: AppColors.primary,
              child: Icon(Icons.person, color: Colors.white, size: 20),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    widget.customerName,
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: AppColors.textMain),
                    overflow: TextOverflow.ellipsis,
                  ),
                  const Text('Customer', style: TextStyle(color: AppColors.textMuted, fontSize: 12)),
                ],
              ),
            ),
          ],
        ),
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

    return Column(
      children: [
        Expanded(
          child: _messages.isEmpty
              ? const Center(child: Text('No messages yet. Say hello!', style: TextStyle(color: AppColors.textMuted)))
              : ListView.builder(
                  controller: _scrollController,
                  padding: const EdgeInsets.all(16),
                  itemCount: _messages.length,
itemBuilder: (context, index) {
                    final m = _messages[index];
                    // The backend returns `sender_type` ('rider' or 'customer').
                    final isFromRider = (m['sender_type']?.toString() ?? '') == 'rider';
                    final createdAt = DateTime.tryParse(m['created_at']?.toString() ?? '');
                    final time = createdAt != null ? DateFormat('h:mm a').format(createdAt) : '';
                    return _MessageBubble(
                      isFromRider: isFromRider,
                      message: m['message']?.toString() ?? '',
                      time: time,
                    );
                  },
                ),
        ),
        _buildInputBar(),
      ],
    );
  }

  Widget _buildInputBar() {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
      decoration: BoxDecoration(
        color: Colors.white,
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.05), blurRadius: 8, offset: const Offset(0, -2))],
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            Expanded(
              child: TextField(
                controller: _messageController,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => _sendMessage(),
                decoration: InputDecoration(
                  hintText: 'Type a message...',
                  filled: true,
                  fillColor: AppColors.backgroundGrey,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(24), borderSide: BorderSide.none),
                ),
              ),
            ),
            const SizedBox(width: 8),
            IconButton(
              onPressed: _sending ? null : _sendMessage,
              icon: const Icon(Icons.send, color: AppColors.primary),
              disabledColor: AppColors.textMuted,
            ),
          ],
        ),
      ),
    );
  }
}

class _MessageBubble extends StatelessWidget {
  final bool isFromRider;
  final String message;
  final String time;
  const _MessageBubble({
    required this.isFromRider,
    required this.message,
    required this.time,
  });

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: isFromRider ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.75),
        decoration: BoxDecoration(
          color: isFromRider ? AppColors.primary : Colors.white,
          borderRadius: BorderRadius.only(
            topLeft: const Radius.circular(16),
            topRight: const Radius.circular(16),
            bottomLeft: Radius.circular(isFromRider ? 16 : 4),
            bottomRight: Radius.circular(isFromRider ? 4 : 16),
          ),
          boxShadow: [
            BoxShadow(color: Colors.black.withValues(alpha: 0.05), blurRadius: 6, offset: const Offset(0, 2)),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              message,
              style: TextStyle(
                color: isFromRider ? Colors.white : AppColors.textMain,
                fontSize: 14,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              time,
              style: TextStyle(
                color: isFromRider ? Colors.white70 : AppColors.textMuted,
                fontSize: 10,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
