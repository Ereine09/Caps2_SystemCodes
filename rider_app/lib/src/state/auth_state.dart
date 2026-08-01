import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

class AuthState extends ChangeNotifier {
  String? _token;
  String? _username;
  int? _userId;

  String? get token => _token;
  String? get username => _username;
  int? get userId => _userId;

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

    // If a token is found, notify listeners to update the UI
    if (isLoggedIn) {
      notifyListeners();
    }
  }

  // Method to be called on logout
  Future<void> logout() async {
    _token = null;
    _username = null;
    _userId = null;

    // Clear credentials from persistent storage
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('authToken');
    await prefs.remove('username');
    await prefs.remove('userId');
    
    notifyListeners();
  }
}
