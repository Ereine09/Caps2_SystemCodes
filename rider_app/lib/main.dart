import 'package:flutter/material.dart';
import 'package:provider/provider.dart';


import 'src/state/auth_state.dart';
import 'src/ui/login_screen.dart';
import 'src/ui/home_screen.dart';

void main() {
  runApp(
    ChangeNotifierProvider(
      create: (_) => AuthState(),
      child: const RiderApp(),
    ),
  );
}

class RiderApp extends StatelessWidget {
  const RiderApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Rider App',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.green),
        useMaterial3: true,
      ),
      home: Consumer<AuthState>(
        builder: (context, auth, _) {
          if (auth.isAuthed) {
            return const HomeScreen();
          }
          return const LoginScreen();
        },
      ),
    );
  }
}

