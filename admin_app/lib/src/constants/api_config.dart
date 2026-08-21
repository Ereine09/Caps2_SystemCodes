import 'package:flutter/foundation.dart';

/// Centralized API constants for the application.

const String _webBaseUrl = 'http://localhost/loyalty_managements'; // For Chrome browser testing
const String _emulatorBaseUrl = 'http://10.0.2.2/loyalty_managements'; // For Android Emulator

// --- SELECT YOUR MOBILE TARGET ENVIRONMENT ---
const String _mobileBaseUrl = _emulatorBaseUrl;

// This is the final URL the app will use. No need to change this line.
const String baseUrl = kIsWeb ? _webBaseUrl : _mobileBaseUrl;
const String apiUrl = '$baseUrl/api/admin_app_api.php';