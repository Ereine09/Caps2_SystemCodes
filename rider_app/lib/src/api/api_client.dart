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

  /// Fetches a list of assigned deliveries for a rider.
  Future<List<dynamic>> getDeliveries({int riderId = 1}) async {
    // In a real app, riderId would come from auth state.
    final response = await getJson('/rider_api.php?action=get_deliveries&rider_id=$riderId');
    if (response.data['success'] == true) {
      return response.data['data'];
    } else {
      throw Exception('Failed to load deliveries: ${response.data['message']}');
    }
  }

  /// Fetches the full details for a single order.
  Future<Map<String, dynamic>> getDeliveryDetails(int orderId) async {
    final response = await getJson('/rider_api.php?action=get_delivery_details&order_id=$orderId');
    if (response.data['success'] == true) {
      return response.data['data'];
    } else {
      throw Exception('Failed to load delivery details: ${response.data['message']}');
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