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
        useMaterial3: true,
        scaffoldBackgroundColor: AppColors.background,
        primaryColor: AppColors.primary,
        colorScheme: ColorScheme.fromSwatch(
          primarySwatch: Colors.blueGrey,
        ).copyWith(
          primary: AppColors.primary,
          secondary: AppColors.accent,
        ),
        cardTheme: CardThemeData(
          color: AppColors.cardBg,
          elevation: 1,
          shadowColor: const Color(0x1A142235),
          margin: EdgeInsets.zero,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
            side: const BorderSide(color: Color(0xFFE3EAF0)),
          ),
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: AppColors.cardBg,
          foregroundColor: AppColors.textMain,
          elevation: 0,
          scrolledUnderElevation: 0,
          centerTitle: false,
        ),
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: AppColors.backgroundGrey,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide.none,
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: Color(0xFFDCE5EC)),
          ),
          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: AppColors.accent, width: 1.5),
          ),
        ),
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.primary,
            foregroundColor: Colors.white,
            minimumSize: const Size.fromHeight(52),
            elevation: 0,
            padding: const EdgeInsets.symmetric(vertical: 15, horizontal: 20),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
        snackBarTheme: SnackBarThemeData(
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          backgroundColor: AppColors.textMain,
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
