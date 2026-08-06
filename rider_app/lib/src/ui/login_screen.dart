import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../state/auth_state.dart';
import '../constants/api_constants.dart';
import '../api/api_client.dart';
import '../constants/colors.dart';
import 'register_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _isLoading = false;
  String? _error;

  Future<void> _performLogin() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() => _isLoading = true);
    setState(() => _error = null);

    try {
      final apiClient = ApiClient(baseUrl: baseUrl);
      final response = await apiClient.postJson(
        '/modules/rider/rider_login_api.php',
        body: {
          'username_email': _usernameController.text,
          'password': _passwordController.text,
        },
      );

      final data = response.data;
      if (data != null && data['success'] == true) {
        final authData = (data['data'] as Map<String, dynamic>?) ?? {};
        final auth = context.read<AuthState>();
        await auth.login(
          authData['token']?.toString() ?? '',
          authData['username']?.toString() ?? '',
          int.tryParse(authData['user_id']?.toString() ?? '0') ?? 0,
        );
      } else {
        throw Exception(data?['message'] ?? 'An unknown login error occurred.');
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e.toString();
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background, // Fixed getter reference
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24.0),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 400),
            child: Card(
              elevation: 4,
              shadowColor: Colors.black12,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
              color: Colors.white,
              child: Padding(
                padding: const EdgeInsets.all(28.0),
                child: Form(
                  key: _formKey,
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Container(
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: AppColors.primary.withOpacity(0.1),
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.delivery_dining, size: 48, color: AppColors.primary),
                      ),
                      const SizedBox(height: 16),
                      const Text(
                        'Rider Portal',
                        style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: AppColors.textMain),
                        textAlign: TextAlign.center,
                      ),
                      const Text(
                        'Sign in to manage your deliveries',
                        style: TextStyle(fontSize: 13, color: AppColors.textMuted),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 24),
                      if (_error != null) ...[
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Colors.red.shade50,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: Colors.red.shade200),
                          ),
                          child: Text(_error!, style: TextStyle(color: Colors.red.shade800, fontSize: 13), textAlign: TextAlign.center),
                        ),
                        const SizedBox(height: 16),
                      ],
                      TextFormField(
                        controller: _usernameController,
                        decoration: InputDecoration(
                          labelText: 'Username or Email',
                          prefixIcon: const Icon(Icons.person_outline),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        validator: (value) => value == null || value.isEmpty ? 'Username is required' : null,
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: _passwordController,
                        obscureText: true,
                        decoration: InputDecoration(
                          labelText: 'Password',
                          prefixIcon: const Icon(Icons.lock_outline),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        validator: (value) => value == null || value.isEmpty ? 'Password is required' : null,
                      ),
                      const SizedBox(height: 24),
                      _isLoading
                          ? const Center(child: CircularProgressIndicator())
                          : ElevatedButton(
                              onPressed: _performLogin,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: AppColors.primary,
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 16),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                elevation: 2,
                              ),
                              child: const Text('Login', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                            ),
                      const SizedBox(height: 16),
                      TextButton(
                        onPressed: _isLoading
                            ? null
                            : () {
                                Navigator.of(context).push(
                                  MaterialPageRoute(builder: (context) => const RegisterScreen()),
                                );
                              },
                        child: const Text("Don't have an account? Register", style: TextStyle(color: AppColors.primary)),
                      )
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