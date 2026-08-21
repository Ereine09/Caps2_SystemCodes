import 'dart:convert';
import 'dart:io';
import 'dart:async';
import 'package:http/http.dart' as http;
import '../constants/api_config.dart';
import '../models/order.dart';

/// A service class for handling all API communications.
class ApiService {
  /// Attempts to log in a user with the given credentials.
  ///
  /// Returns a map with user data and token on success.
  /// Throws an exception with an error message on failure.
  Future<Map<String, dynamic>> login(String username, String password) async {
    try {
      final response = await http.post(
        Uri.parse('$apiUrl?action=login'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'action': 'login',
          'username': username,
          'password': password,
        }),
      ).timeout(const Duration(seconds: 15));

      final responseData = _decode(response.body);

      if (response.statusCode == 200 && responseData['success'] == true) {
        return responseData['data'] as Map<String, dynamic>;
      } else {
        throw Exception(responseData['message'] ?? 'Failed to login.');
      }
    } on SocketException {
      throw Exception('Unable to connect to the server. Please check that XAMPP/Apache is running.');
    } on TimeoutException {
      throw Exception('Unable to connect to the server. Please check that XAMPP/Apache is running.');
    } on http.ClientException {
      throw Exception('Could not connect to the server. Please check the API URL.');
    } catch (e) {
      // Re-throw other exceptions (like the one from the server response)
      rethrow;
    }
  }

  Future<Order> lookupOrder(String qrData, String token) async {
    final response = await _post(
      action: 'lookup_order',
      body: {'qr_data': qrData},
      token: token,
    );
    return Order.fromJson(response['data'] as Map<String, dynamic>);
  }

  Future<void> updateOrderStatus({
    required int orderId,
    required String status,
    required String token,
  }) async {
    if (!Order.allowedStatuses.contains(status)) {
      throw Exception('That status is not available.');
    }
    await _post(
      action: 'update_status',
      body: {'order_id': orderId, 'status': status},
      token: token,
    );
  }

  Future<Map<String, dynamic>> _post({
    required String action,
    required Map<String, dynamic> body,
    String? token,
  }) async {
    try {
      final response = await http.post(
        Uri.parse('$apiUrl?action=$action'),
        headers: {
          'Content-Type': 'application/json',
          if (token != null) 'Authorization': 'Bearer $token',
        },
        body: jsonEncode({'action': action, ...body}),
      ).timeout(const Duration(seconds: 15));
      final responseData = _decode(response.body);
      if (response.statusCode >= 200 && response.statusCode < 300 && responseData['success'] == true) {
        return responseData;
      }
      throw Exception(responseData['message'] ?? 'The server could not complete that request.');
    } on SocketException {
      throw Exception('The server is unavailable. Check the API URL and network.');
    } on TimeoutException {
      throw Exception('The server is unavailable. Check that XAMPP/Apache is running.');
    } on http.ClientException {
      throw Exception('Could not connect to the server.');
    }
  }

  Map<String, dynamic> _decode(String body) {
    try {
      final decoded = jsonDecode(body);
      return decoded is Map<String, dynamic> ? decoded : <String, dynamic>{};
    } catch (_) {
      throw Exception('The server returned an invalid response.');
    }
  }
}