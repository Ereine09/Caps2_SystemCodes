import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'src/state/auth_state.dart';
import 'src/ui/login_screen.dart';
import 'src/ui/home_screen.dart';

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
        primarySwatch: Colors.teal,
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
