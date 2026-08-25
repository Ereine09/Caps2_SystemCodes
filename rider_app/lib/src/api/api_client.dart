import 'dart:convert';
import 'package:dio/dio.dart';

class ApiClient {
  final Dio _dio;
  final String baseUrl;

  ApiClient({required this.baseUrl}) : _dio = Dio() {
    _dio.options = BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(
          seconds: 15), // May limit na para hindi mag-hang forever
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
              errorMessage =
                  "Server returned status code: ${e.response?.statusCode}";
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

  Future<Response<T>> postJson<T>(String path,
      {required Map<String, dynamic> body, Map<String, dynamic>? headers}) {
    return _dio.post<T>(path, data: body, options: Options(headers: headers));
  }

  Future<Response<T>> getJson<T>(String path, {Map<String, dynamic>? headers}) {
    return _dio.get<T>(path, options: Options(headers: headers));
  }

  Future<bool> isRemittanceReferenceAvailable({
    required String referenceNumber,
    required String token,
  }) async {
    final response = await postJson(
      '/modules/rider/rider_remittance_api.php',
      body: {
        'action': 'check_reference',
        'reference_number': referenceNumber,
      },
      headers: {'Authorization': 'Bearer $token'},
    );
    if (response.data['success'] == true) {
      return response.data['data']?['available'] == true;
    }
    throw Exception(response.data['message'] ?? 'Could not validate reference number.');
  }

  /// Fetches the list of assigned deliveries for the authenticated rider.
  ///
  /// Uses `rider_orders_api.php` and passes the JWT so the backend knows which
  /// rider is asking. No hardcoded rider id is required.
  Future<List<dynamic>> getDeliveries({String token = ''}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
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

  /// Fetches the Phase 2 Earning and Performance dashboard for the rider.
  ///
  /// Returns the full `data` map from `rider_earnings_api.php`, which contains
  /// `summary`, `earnings_history`, `remittance_history`, and `chart`.
  Future<Map<String, dynamic>> getEarnings({String token = ''}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_earnings_api.php',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return Map<String, dynamic>.from(data);
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to load earnings data');
    }
  }

  /// Fetches a short AI performance strategy for the authenticated rider.
  Future<String> getRiderAiInsight({String token = ''}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_ai_insight_api.php',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return data['insight']?.toString() ?? '';
    } else {
      throw Exception(response.data['message'] ?? 'Failed to load AI insight');
    }
  }

  /// Fetches chart data for the earnings dashboard for a given period
  /// (`weekly` or `monthly`).
  Future<Map<String, dynamic>> getEarningsChart({
    String period = 'monthly',
    String token = '',
  }) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_earnings_api.php?action=get_chart&period=$period',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return Map<String, dynamic>.from(data);
    } else {
      throw Exception(response.data['message'] ?? 'Failed to load chart data');
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
      throw Exception(
          response.data['message'] ?? 'Failed to update rider order');
    }
  }

  Future<Map<String, dynamic>> updateDeliveryStatus({
    required int orderId,
    required int deliveryId,
    required String status,
    required String qrCode,
    required String token,
  }) async {
    final response = await postJson(
      '/modules/rider/rider_update_status_api.php',
      body: {
        'order_id': orderId,
        'delivery_id': deliveryId,
        'status': status,
        'qr_code': qrCode,
      },
      headers: {'Authorization': 'Bearer $token'},
    );
    if (response.data['success'] == true) return Map<String, dynamic>.from(response.data);
    throw Exception(response.data['message'] ?? 'Failed to update delivery status');
  }

  /// Uploads a proof-of-delivery photo (base64) + optional notes for an order.
  ///
  /// POSTs to `rider_proof_api.php` with the rider JWT. The backend saves the
  /// image to `uploads/proofs/` and logs it into `delivery_tracking`.
  Future<Map<String, dynamic>> uploadProofOfDelivery({
    required int orderId,
    required String imageBase64,
    String notes = '',
    int deliveryId = 0,
    String token = '',
  }) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await postJson(
      '/modules/rider/rider_proof_api.php',
      body: {
        'order_id': orderId,
        'delivery_id': deliveryId,
        'image': imageBase64,
        'notes': notes,
      },
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data'] ?? {});
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to upload proof of delivery');
    }
  }

  /// Fetches the full details for a single order.
  Future<Map<String, dynamic>> getDeliveryDetails(int orderId,
      {String token = ''}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_orders_api.php?action=get_order_details&id=$orderId',
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data']);
    } else {
      throw Exception(
          'Failed to load delivery details: ${response.data['message']}');
    }
  }

  /// Fetches the current on-duty status of the authenticated rider.
  ///
  /// GETs `rider_status_api.php` and passes the JWT so the backend knows which
  /// rider is asking. Returns the parsed `data` map.
  Future<Map<String, dynamic>> getRiderStatus({String token = ''}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_status_api.php',
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data'] ?? {});
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to fetch rider status');
    }
  }

  /// Toggles the on-duty status of the authenticated rider.
  ///
  /// POSTs `is_on_duty` to `rider_status_api.php` and returns the parsed `data`.
  Future<Map<String, dynamic>> toggleRiderStatus({
    required bool isOnDuty,
    String token = '',
  }) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await postJson(
      '/modules/rider/rider_status_api.php',
      body: {'is_on_duty': isOnDuty},
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data'] ?? {});
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to update rider status');
    }
  }

  /// Fetches the unread notification count for the authenticated rider.
  Future<int> getUnreadNotificationCount({String token = ''}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_notifications_api.php?action=get_unread_count',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return int.tryParse(data['unread_count']?.toString() ?? '0') ?? 0;
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to fetch unread count');
    }
  }

  /// Fetches the list of notifications for the authenticated rider.
  Future<List<dynamic>> getNotifications(
      {String token = '', int limit = 50}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_notifications_api.php?action=get_list&limit=$limit',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return (data['notifications'] as List?) ?? [];
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to fetch notifications');
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
      throw Exception(
          response.data['message'] ?? 'Failed to mark notifications as read');
    }
  }

  /// Fetches the authenticated rider's profile (username, email, vehicle type,
  /// plate number, on-duty status) from `rider_profile_api.php`.
  Future<Map<String, dynamic>> getRiderProfile({String token = ''}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_profile_api.php',
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data'] ?? {});
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to fetch rider profile');
    }
  }

  /// Updates the rider's vehicle type and plate number via `rider_profile_api.php`.
  Future<Map<String, dynamic>> updateRiderProfile({
    required String vehicleType,
    required String plateNumber,
    String token = '',
  }) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await postJson(
      '/modules/rider/rider_profile_api.php',
      body: {
        'vehicle_type': vehicleType,
        'plate_number': plateNumber,
      },
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data'] ?? {});
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to update rider profile');
    }
  }

  /// Fetches the list of customer conversations for the rider from
  /// `rider_messaging_api.php`.
  Future<List<dynamic>> getMessagingConversations({String token = ''}) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_messaging_api.php?action=get_conversations',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return (data['conversations'] as List?) ?? [];
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to load conversations');
    }
  }

  /// Fetches the full message history with a given customer.
  Future<List<dynamic>> getMessagingConversation({
    required int customerId,
    String token = '',
  }) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await getJson(
      '/modules/rider/rider_messaging_api.php?action=get_conversation&customer_id=$customerId',
      headers: headers,
    );
    if (response.data['success'] == true) {
      final data = (response.data['data'] as Map?) ?? {};
      return (data['messages'] as List?) ?? [];
    } else {
      throw Exception(
          response.data['message'] ?? 'Failed to load conversation');
    }
  }

  /// Sends a message from the rider to a customer.
  Future<Map<String, dynamic>> sendRiderMessage({
    required int customerId,
    required String message,
    String token = '',
  }) async {
    final headers =
        token.isNotEmpty ? {'Authorization': 'Bearer $token'} : null;
    final response = await postJson(
      '/modules/rider/rider_messaging_api.php',
      body: {
        'action': 'send_message',
        'customer_id': customerId,
        'message': message,
      },
      headers: headers,
    );
    if (response.data['success'] == true) {
      return Map<String, dynamic>.from(response.data['data'] ?? {});
    } else {
      throw Exception(response.data['message'] ?? 'Failed to send message');
    }
  }

  /// Previews or confirms the delivery confirmation QR code.
  Future<Map<String, dynamic>> verifyDeliveryQR(String qrToken,
      {required String token, bool confirm = false}) async {
    final qrData = jsonDecode(qrToken);
    if (qrData is! Map || qrData['delivery_id'] == null) {
      throw Exception('Invalid QR code format.');
    }

    final deliveryId = int.tryParse(qrData['delivery_id'].toString());
    if (deliveryId == null || deliveryId <= 0) {
      throw Exception('Invalid delivery ID.');
    }

    final response = await postJson(
      '/modules/rider/rider_qr_confirm_api.php',
      body: {
        'delivery_id': deliveryId,
        'qr_code': qrToken,
        'action': confirm ? 'confirm' : 'preview',
      },
      headers: {
        'Authorization': 'Bearer $token',
      },
    );

    // Dio interceptor handles non-200 status codes, so we check the business logic success flag.
    if (response.data['success'] == true) {
      return response.data;
    } else {
      // Throw an exception with the specific error message from the backend.
      throw Exception(
          response.data['message'] ?? 'QR code verification failed.');
    }
  }
}
