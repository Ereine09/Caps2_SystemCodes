import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../widgets/api_error_banner.dart';
import '../constants/colors.dart'; // Import AppColors

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _usernameCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _confirmPasswordCtrl = TextEditingController();

  bool _loading = false;
  String? _error;

  final String _baseUrl = baseUrl;

  Future<void> _register() async {
    setState(() {
      _error = null;
    });

    if (!(_formKey.currentState?.validate() ?? false)) return;

    if (_passwordCtrl.text != _confirmPasswordCtrl.text) {
      setState(() => _error = "Passwords do not match!");
      return;
    }

    setState(() => _loading = true);

    try {
      final api = ApiClient(baseUrl: _baseUrl);
      final res = await api.postJson<Map<String, dynamic>>(
        '/modules/rider/rider_register_api.php',
        body: {
          'username': _usernameCtrl.text.trim(),
          'email': _emailCtrl.text.trim(),
          'password': _passwordCtrl.text,
        },
      );

      final body = res.data ?? {};
      if (body['success'] != true) {
        throw Exception(body['message'] ?? 'Registration failed');
      }

      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(body['message'] ?? 'Registration successful! Please login.'),
          backgroundColor: Colors.green,
        ),
      );
      Navigator.of(context).pop();
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
    _emailCtrl.dispose();
    _passwordCtrl.dispose();
    _confirmPasswordCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Rider Registration'),
        backgroundColor: AppColors.cardBg, // Consistent with login_screen's body background
        elevation: 0.5,
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 400), // Max width for the card
            child: Card(
              elevation: 4,
              shadowColor: Colors.black12,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
              color: AppColors.cardBg,
              child: Padding(
                padding: const EdgeInsets.all(28.0), // Consistent padding with login_screen
                child: Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Text(
                        'Rider Registration',
                        style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: AppColors.textMain),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 24),
                      if (_error != null) ...[
                        ApiErrorBanner(message: _error!),
                        const SizedBox(height: 16),
                      ],
                      TextFormField(
                        controller: _usernameCtrl,
                        decoration: const InputDecoration(labelText: 'Username', prefixIcon: Icon(Icons.person_outline)),
                        validator: (v) => (v == null || v.trim().isEmpty) ? 'Required' : null,
                      ),
                      const SizedBox(height: 16), // Consistent spacing
                      TextFormField(
                        controller: _emailCtrl,
                        decoration: const InputDecoration(labelText: 'Email Address', prefixIcon: Icon(Icons.email_outlined)),
                        keyboardType: TextInputType.emailAddress,
                        validator: (v) {
                          if (v == null || v.trim().isEmpty) return 'Required';
                          if (!RegExp(r'^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$').hasMatch(v.trim())) {
                            return 'Please enter a valid email address';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 16), // Consistent spacing
                      TextFormField(
                        controller: _passwordCtrl,
                        decoration: const InputDecoration(labelText: 'Password', prefixIcon: Icon(Icons.lock_outline)),
                        obscureText: true,
                        validator: (v) => (v == null || v.isEmpty) ? 'Required' : null,
                      ),
                      const SizedBox(height: 16), // Consistent spacing
                      TextFormField(
                        controller: _confirmPasswordCtrl,
                        decoration: const InputDecoration(labelText: 'Confirm Password', prefixIcon: Icon(Icons.lock_outline)),
                        obscureText: true,
                        validator: (v) => (v == null || v.isEmpty) ? 'Required' : null,
                      ),
                      const SizedBox(height: 24),
                      ElevatedButton( // Changed to ElevatedButton to use global theme
                        onPressed: _loading ? null : _register,
                        child: _loading
                            ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                            : const Text('Register', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                      ),
                      const SizedBox(height: 16), // Consistent spacing
                      TextButton(
                        onPressed: _loading ? null : () => Navigator.of(context).pop(),
                        child: const Text("Already have an account? Login here", style: TextStyle(color: AppColors.primary)),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}