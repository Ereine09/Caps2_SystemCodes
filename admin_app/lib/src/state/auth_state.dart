import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../services/api_service.dart';

class AuthState extends ChangeNotifier {
  final _storage = const FlutterSecureStorage();
  final _api = ApiService();
  bool _isAuthenticated = false;
  String? _token;
  String? _username;
  String? _role;

  bool get isAuthenticated => _isAuthenticated;
  String? get username => _username;
  String? get token => _token;
  String? get role => _role;

  AuthState() {
    restoreSession();
  }

  Future<void> restoreSession() async {
    final storedToken = await _storage.read(key: 'admin_auth_token');
    final storedUsername = await _storage.read(key: 'admin_username');
    final storedRole = await _storage.read(key: 'admin_role');

    if (storedToken != null && storedToken.isNotEmpty) {
      try {
        await _api.validateSession(storedToken);
        _token = storedToken;
        _username = storedUsername;
        _role = storedRole;
        _isAuthenticated = true;
      } catch (_) {
        await _clearStoredSession();
      }
    }
    notifyListeners();
  }

  Future<void> login(String user, String pass) async {
    final data = await _api.login(user, pass);
    _token = data['token']?.toString();
    _username = data['username']?.toString() ?? user;
    _role = data['role']?.toString().toLowerCase();
    if (_token == null || _token!.isEmpty) throw Exception('Login did not return a session token.');
    if (_role != 'admin' && _role != 'staff') {
      _token = null;
      _username = null;
      _role = null;
      throw Exception('This account is not authorized to use the Admin/Staff application.');
    }
    await _storage.write(key: 'admin_auth_token', value: _token);
    await _storage.write(key: 'admin_username', value: _username);
    await _storage.write(key: 'admin_role', value: _role);
    _isAuthenticated = true;
    notifyListeners();
  }

  Future<void> logout() async {
    _token = null;
    _username = null;
    _role = null;
    _isAuthenticated = false;
    await _clearStoredSession();
    notifyListeners();
  }

  Future<void> _clearStoredSession() async {
    await _storage.delete(key: 'admin_auth_token');
    await _storage.delete(key: 'admin_username');
    await _storage.delete(key: 'admin_role');
  }
}