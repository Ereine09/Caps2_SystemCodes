import 'package:dio/dio.dart';

class ApiClient {
  final Dio _dio;
  final String baseUrl;

  ApiClient({required this.baseUrl}) : _dio = Dio() {
    _dio.options = BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: Duration.zero, // No limit
      receiveTimeout: Duration.zero, // No limit
      contentType: Headers.jsonContentType,
      responseType: ResponseType.json,
    );
  }

  Future<Response<T>> postJson<T>(String path, {required Map<String, dynamic> body, Map<String, dynamic>? headers}) {
    return _dio.post<T>(path, data: body, options: Options(headers: headers));
  }

  Future<Response<T>> getJson<T>(String path, {Map<String, dynamic>? headers}) {
    return _dio.get<T>(path, options: Options(headers: headers));
  }
}
