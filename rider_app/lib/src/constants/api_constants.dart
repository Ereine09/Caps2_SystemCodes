import 'package:flutter/foundation.dart';

/// Centralized API constants for the application.

final String _webBaseUrl = 'http://localhost/loyalty_managements'; // For Chrome browser testing
final String _emulatorBaseUrl = 'http://10.0.2.2/loyalty_managements'; // For Android Emulator

// IMPORTANT: This is your PC's IPv4 address from `ipconfig` (Wi-Fi adapter).
final String _physicalDeviceBaseUrl = 'http://192.168.1.84/loyalty_managements';

// --- SELECT YOUR MOBILE TARGET ENVIRONMENT ---
// Use _emulatorBaseUrl for the Android emulator.
// Use _physicalDeviceBaseUrl for a real phone connected via Wi-Fi.
final String _mobileBaseUrl = _emulatorBaseUrl;

// This is the final URL the app will use. No need to change this line.
final String baseUrl = kIsWeb ? _webBaseUrl : _mobileBaseUrl;

/// Base URL for endpoints inside the /customer folder (e.g. customer/rider_api.php).
final String customerBaseUrl = '$baseUrl/customer';

/// WebSocket server URL for real-time notifications.
/// The Ratchet WS server runs on port 8080 on the same host as the API.
/// We derive the host from `baseUrl` so it works on web, emulator, and device.
final String wsUrl = _resolveWsUrl(baseUrl);

String _resolveWsUrl(String httpBase) {
  // Convert http://host/path to ws://host:8080
  final uri = Uri.tryParse(httpBase);
  if (uri == null) {
    return 'ws://localhost:8080';
  }
  final host = uri.host;
  return 'ws://$host:8080';
}
