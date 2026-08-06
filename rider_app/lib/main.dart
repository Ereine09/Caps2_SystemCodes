import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'src/state/auth_state.dart';
import 'src/ui/login_screen.dart';
import 'src/ui/home_screen.dart';
import 'src/constants/colors.dart'; // Import AppColors

void main() {
  runApp(
    ChangeNotifierProvider(
      create: (context) => AuthState(),
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
      home: Consumer<AuthState>(
        builder: (context, auth, child) {
          // If logged in, show HomeScreen, otherwise show LoginScreen
          return auth.isLoggedIn ? const HomeScreen() : const LoginScreen();
        },
      ),
    );
  }
}
