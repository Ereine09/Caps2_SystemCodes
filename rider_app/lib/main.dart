import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'src/state/auth_state.dart';
import 'src/state/notification_state.dart';
import 'src/services/realtime_service.dart';
import 'src/ui/login_screen.dart';
import 'src/ui/home_screen.dart';
import 'src/constants/colors.dart'; // Import AppColors

void main() {
  runApp(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (context) => AuthState()),
        ChangeNotifierProvider(create: (context) => NotificationState()),
      ],
      child: const MyApp(),
    ),
  );
}

class MyApp extends StatefulWidget {
  const MyApp({super.key});

  @override
  State<MyApp> createState() => _MyAppState();
}

class _MyAppState extends State<MyApp> {
  @override
  void initState() {
    super.initState();
    // Check for a saved login session when the app starts
    context.read<AuthState>().checkLoginStatus();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Rider App',
      debugShowCheckedModeBanner: false, // Removes the DEBUG banner in the corner
      theme: ThemeData(
        scaffoldBackgroundColor: AppColors.background,
        primaryColor: AppColors.primary, // Set primary color for general use
        colorScheme: ColorScheme.fromSwatch(
          primarySwatch: Colors.teal, // Fallback for some widgets
        ).copyWith(
          primary: AppColors.primary,
          secondary: AppColors.accent,
        ),
        cardTheme: CardThemeData(
          color: AppColors.cardBg,
          elevation: 4,
          shadowColor: Colors.black12,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        ),
        inputDecorationTheme: InputDecorationTheme(
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: AppColors.primary),
          ),
        ),
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.primary,
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
      ),
      home: const _SessionGate(),
    );
  }
}

/// Switches between Login and Home based on auth, and manages the RealtimeService
/// (WebSocket connection) lifecycle for the logged-in rider.
class _SessionGate extends StatefulWidget {
  const _SessionGate();

  @override
  State<_SessionGate> createState() => _SessionGateState();
}

class _SessionGateState extends State<_SessionGate> {
  RealtimeService? _realtimeService;

  @override
  Widget build(BuildContext context) {
    final token = context.select<AuthState, String>((a) => a.token ?? '');
    final notificationState = context.read<NotificationState>();
    final auth = context.read<AuthState>();
    final isLoggedIn = token.isNotEmpty;

    // Start/stop the WS service based on login state.
    if (isLoggedIn && _realtimeService == null) {
      _realtimeService = RealtimeService(
        notificationState: notificationState,
        token: token,
      );
      _realtimeService!.connect();
    } else if (!isLoggedIn && _realtimeService != null) {
      _realtimeService!.dispose();
      _realtimeService = null;
    }

    return auth.isLoggedIn ? const HomeScreen() : const LoginScreen();
  }

  @override
  void dispose() {
    _realtimeService?.dispose();
    _realtimeService = null;
    super.dispose();
  }
}
