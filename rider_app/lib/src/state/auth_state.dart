import 'package:flutter/foundation.dart';

class AuthState extends ChangeNotifier {
  String? _token;
  String? _username;
  int? _userId;

  bool get isAuthed => _token != null && _token!.isNotEmpty;
  String? get token => _token;
  String? get username => _username;
  int? get userId => _userId;

  void setAuth({required String token, required String username, required int userId}) {
    _token = token;
    _username = username;
    _userId = userId;
    notifyListeners();
  }

  void clear() {
    _token = null;
    _username = null;
    _userId = null;
    notifyListeners();
  }
}

