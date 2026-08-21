import 'package:admin_app/src/screens/dashboard_screen.dart';
import 'package:admin_app/src/screens/login_screen.dart';
import 'package:admin_app/src/state/auth_state.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

void main() {
  runApp(const AdminApp());
}

class AdminApp extends StatelessWidget {
  const AdminApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => AuthState(),
      child: MaterialApp(
        title: 'DPS Admin',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF111111)),
          scaffoldBackgroundColor: const Color(0xFFFAFAF9),
          inputDecorationTheme: InputDecorationTheme(
            filled: true,
            fillColor: Colors.white,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: const BorderSide(color: Color(0xFFD8D8D8)),
            ),
          ),
          elevatedButtonTheme: ElevatedButtonThemeData(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF111111),
              foregroundColor: Colors.white,
              minimumSize: const Size.fromHeight(50),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
          ),
          appBarTheme: const AppBarTheme(
            backgroundColor: Color(0xFFFAFAF9),
            elevation: 0,
            iconTheme: IconThemeData(color: Colors.black87),
            titleTextStyle: TextStyle(
              color: Colors.black87,
              fontSize: 18,
              fontWeight: FontWeight.bold,
            ),
          ),
        ),
        home: Consumer<AuthState>(
          builder: (context, auth, _) {
            // This logic will be updated once AuthState is implemented
            if (auth.isAuthenticated) {
              return const DashboardScreen();
            }
            return const LoginScreen();
          },
        ),
      ),
    );
  }
}