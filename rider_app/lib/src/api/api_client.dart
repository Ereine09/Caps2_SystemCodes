import 'package:dio/dio.dart';

class ApiClient {
  final Dio _dio;
  final String baseUrl;

  ApiClient({required this.baseUrl}) : _dio = Dio() {
    _dio.options = BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 15), // May limit na para hindi mag-hang forever
      receiveTimeout: const Duration(seconds: 15),
      contentType: Headers.jsonContentType,
      responseType: ResponseType.json,
    );

    // Nagdagdag tayo ng Interceptor para sa awtomatikong pag-handle ng errors
    _dio.interceptors.add(
      InterceptorsWrapper(
        onError: (DioException e, handler) {
          String errorMessage = "Something went wrong";

          // Kung may response galing sa PHP backend mo (tulad ng 400 Bad Request)
          if (e.response != null) {
            final data = e.response?.data;
            if (data is Map && data.containsKey('message')) {
              // Kukunin natin ang pinasadyang message (e.g. "Username or Email is already taken")
              errorMessage = data['message'].toString();
            } else if (data != null) {
              errorMessage = data.toString();
            } else {
              errorMessage = "Server returned status code: ${e.response?.statusCode}";
            }
          } else {
            // Kung walang response (e.g. offline o hindi gumagana ang local server)
            if (e.type == DioExceptionType.connectionTimeout ||
                e.type == DioExceptionType.receiveTimeout) {
              errorMessage = "Connection timeout. Please try again.";
            } else {
              errorMessage = "Network error: Unable to connect to the server.";
            }
          }

          // Itatapon natin ang malinis na message imbes na ang mahabang DioException block
          return handler.reject(
            DioException(
              requestOptions: e.requestOptions,
              response: e.response,
              type: e.type,
              error: errorMessage, // Dito nakalagay ang malinis na text
            ),
          );
        },
      ),
    );
  }

  Future<Response<T>> postJson<T>(String path, {required Map<String, dynamic> body, Map<String, dynamic>? headers}) {
    return _dio.post<T>(path, data: body, options: Options(headers: headers));
  }

  Future<Response<T>> getJson<T>(String path, {Map<String, dynamic>? headers}) {
    return _dio.get<T>(path, options: Options(headers: headers));
  }

  /// Fetches the list of assigned deliveries for the authenticated rider.
  ///
  /// Uses `rider_orders_api.php` and passes the JWT so the backend knows which
  /// rider is asking. No hardcoded rider id is required.
  Future<List<dynamic>> getDeliveries({String token = ''}) async {
    final headers = token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_orders_api.php',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      final assignments = (data['assignments'] as List?) ?? [];
      return assignments;
    } else {
      throw Exception('Failed to load deliveries: ${response.data['message']}');
    }
  }

  Future<Map<String, dynamic>> updateRiderOrder({
    required int orderId,
    required String action,
    String? token,
  }) async {
    final headers = token != null ? {'Authorization': 'Bearer $token'} : null;
    final response = await postJson(
      '/modules/rider/update_rider_order.php',
      body: {
        'order_id': orderId,
        'action': action,
      },
      headers: headers,
    );

    if (response.data['success'] == true) {
      return response.data;
    } else {
      throw Exception(response.data['message'] ?? 'Failed to update rider order');
    }
  }

  /// Fetches the full details for a single order.
  Future<Map<String, dynamic>> getDeliveryDetails(int orderId, {String token = ''}) async {
    final headers = token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_orders_api.php?action=get_order_details&id=$orderId',
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data']);
    } else {
      throw Exception('Failed to load delivery details: ${response.data['message']}');
    }
  }

/// Fetches the current on-duty status of the authenticated rider.
  ///
  /// GETs `rider_status_api.php` and passes the JWT so the backend knows which
  /// rider is asking. Returns the parsed `data` map.
  Future<Map<String, dynamic>> getRiderStatus({String token = ''}) async {
    final headers = token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_status_api.php',
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data'] ?? {});
    } else {
      throw Exception(response.data['message'] ?? 'Failed to fetch rider status');
    }
  }

  /// Toggles the on-duty status of the authenticated rider.
  ///
  /// POSTs `is_on_duty` to `rider_status_api.php` and returns the parsed `data`.
  Future<Map<String, dynamic>> toggleRiderStatus({
    required bool isOnDuty,
    String token = '',
  }) async {
    final headers = token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await postJson(
      '/modules/rider/rider_status_api.php',
      body: {'is_on_duty': isOnDuty},
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data'] ?? {});
    } else {
      throw Exception(response.data['message'] ?? 'Failed to update rider status');
    }
  }

/// Fetches the unread notification count for the authenticated rider.
  Future<int> getUnreadNotificationCount({String token = ''}) async {
    final headers = token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_notifications_api.php?action=get_unread_count',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return int.tryParse(data['unread_count']?.toString() ?? '0') ?? 0;
    } else {
      throw Exception(response.data['message'] ?? 'Failed to fetch unread count');
    }
  }

  /// Fetches the list of notifications for the authenticated rider.
  Future<List<dynamic>> getNotifications({String token = '', int limit = 50}) async {
    final headers = token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_notifications_api.php?action=get_list&limit=$limit',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return (data['notifications'] as List?) ?? [];
    } else {
      throw Exception(response.data['message'] ?? 'Failed to fetch notifications');
    }
  }

  /// Marks all notifications as read for the authenticated rider.
  Future<void> markAllNotificationsRead(String token) async {
    final headers = {'Authorization': 'Bearer $token'};
    final response = await postJson(
      '/modules/rider/rider_notifications_api.php',
      body: {'action': 'mark_all_read'},
      headers: headers,
    );
    if (response.data['success'] != true) {
      throw Exception(response.data['message'] ?? 'Failed to mark notifications as read');
    }
  }

  /// Verifies the delivery confirmation QR code with the backend.
  Future<Map<String, dynamic>> verifyDeliveryQR(String qrToken, int riderId) async {
    final response = await postJson(
      '/rider_api.php?action=verify_delivery_qr',
      body: {
        'qr_token': qrToken,
        'rider_id': riderId,
      },
    );

    // Dio interceptor handles non-200 status codes, so we check the business logic success flag.
    if (response.data['success'] == true) {
      return response.data;
    } else {
      // Throw an exception with the specific error message from the backend.
      throw Exception(response.data['message'] ?? 'QR code verification failed.');
    }
  }
}
