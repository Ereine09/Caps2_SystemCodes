import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

class AuthState extends ChangeNotifier {
  String? _token;
  String? _username;
  int? _userId;
  bool _isOnDuty = false;

  String? get token => _token;
  String? get username => _username;
  int? get userId => _userId;
  bool get isOnDuty => _isOnDuty;

  bool get isLoggedIn => _token != null;

  // Method to be called upon successful login
  Future<void> login(String token, String username, int userId) async {
    _token = token;
    _username = username;
    _userId = userId;

    // Save credentials to persistent storage
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('authToken', token);
    await prefs.setString('username', username);
    await prefs.setInt('userId', userId);

    notifyListeners();
  }

  // Method to check for a saved token on app startup
  Future<void> checkLoginStatus() async {
    final prefs = await SharedPreferences.getInstance();
    _token = prefs.getString('authToken');
    _username = prefs.getString('username');
    _userId = prefs.getInt('userId');
    _isOnDuty = prefs.getBool('isOnDuty') ?? false;

    // If a token is found, notify listeners to update the UI
    if (isLoggedIn) {
      notifyListeners();
    }
  }

  /// Updates and persists the rider's on-duty status.
  Future<void> setOnDuty(bool value) async {
    _isOnDuty = value;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('isOnDuty', value);
    notifyListeners();
  }

  // Method to be called on logout
  Future<void> logout() async {
    _token = null;
    _username = null;
    _userId = null;
    _isOnDuty = false;

    // Clear credentials from persistent storage
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('authToken');
    await prefs.remove('username');
    await prefs.remove('userId');
    await prefs.remove('isOnDuty');

    notifyListeners();
  }
}
