import 'package:dio/dio.dart'; // <-- Tiyaking may import nito sa taas para sa DioException
import 'package:flutter/foundation.dart'; 
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../state/auth_state.dart';
import '../widgets/api_error_banner.dart';
import '../api/api_client.dart';
import 'register_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _usernameCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();

  bool _loading = false;
  String? _error;

  // Automatically switch between Localhost (for Web/iOS) and 10.0.2.2 (for Android Emulator)
  final String _baseUrl = kIsWeb 
      ? 'http://localhost/loyalty_managements' 
      : 'http://10.0.2.2/loyalty_managements';

  Future<void> _login() async {
    setState(() {
      _error = null;
    });

    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() => _loading = true);

    try {
      final api = ApiClient(baseUrl: _baseUrl);
      final res = await api.postJson<Map<String, dynamic>>(
        '/modules/rider/rider_login_api.php',
        body: {
          'username_email': _usernameCtrl.text.trim(),
          'password': _passwordCtrl.text,
        },
      );

      final body = res.data ?? {};
      if (body['success'] != true) {
        throw Exception(body['message'] ?? 'Login failed');
      }

      final data = (body['data'] as Map?) ?? {};
      final token = (data['token'] ?? '') as String;
      final username = (data['username'] ?? '') as String;
      final userId = (data['user_id'] ?? 0) as int;

      if (token.isEmpty) throw Exception('Token missing');

      if (!mounted) return;
      context.read<AuthState>().setAuth(token: token, username: username, userId: userId);
    } catch (e) {
      if (e is DioException) {
        final responseData = e.response?.data;
        if (responseData != null) {
          if (responseData is Map && responseData.containsKey('message')) {
            setState(() => _error = responseData['message'].toString());
          } else {
            setState(() => _error = "Server Error: ${responseData.toString()}");
          }
        } else {
          setState(() => _error = "Network Error: ${e.message}");
        }
      } else {
        setState(() => _error = e.toString().replaceAll('Exception: ', ''));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  void dispose() {
    _usernameCtrl.dispose();
    _passwordCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Rider Login')),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_error != null) ApiErrorBanner(message: _error!),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _usernameCtrl,
                    decoration: const InputDecoration(labelText: 'Username or Email'),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Required' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _passwordCtrl,
                    decoration: const InputDecoration(labelText: 'Password'),
                    obscureText: true,
                    validator: (v) => (v == null || v.isEmpty) ? 'Required' : null,
                  ),
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: _loading ? null : _login,
                    child: _loading
                        ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Text('Login'),
                  ),
                  const SizedBox(height: 12),
                  // BUTTON PAPUNTA SA REGISTER SCREEN
                  TextButton(
                    onPressed: _loading
                        ? null
                        : () {
                            Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (context) => const RegisterScreen(),
                              ),
                            );
                          },
                    child: const Text("Don't have an account? Register here"),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}