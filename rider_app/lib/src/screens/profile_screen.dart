import 'package:flutter/material.dart';
import '../api/api_client.dart';
import '../constants/api_constants.dart';
import '../constants/colors.dart';

/// Edit Profile screen — lets the rider update their vehicle type and plate
/// number. The data is persisted via `rider_profile_api.php`.
class RiderProfileScreen extends StatefulWidget {
  final String token;
  const RiderProfileScreen({super.key, required this.token});

  @override
  State<RiderProfileScreen> createState() => _RiderProfileScreenState();
}

class _RiderProfileScreenState extends State<RiderProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  final _vehicleController = TextEditingController();
  final _plateController = TextEditingController();

  bool _loading = true;
  bool _saving = false;
  String? _error;
  Map<String, dynamic> _profile = {};

  late final ApiClient _api;

  @override
  void initState() {
    super.initState();
    _api = ApiClient(baseUrl: baseUrl);
    _load();
  }

  @override
  void dispose() {
    _vehicleController.dispose();
    _plateController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final profile = await _api.getRiderProfile(token: widget.token);
      if (mounted) {
        _vehicleController.text = profile['vehicle_type']?.toString() ?? '';
        _plateController.text = profile['plate_number']?.toString() ?? '';
        setState(() {
          _profile = profile;
          _loading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e.toString().replaceAll('Exception: ', '');
          _loading = false;
        });
      }
    }
  }

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() => _saving = true);
    try {
      await _api.updateRiderProfile(
        vehicleType: _vehicleController.text.trim(),
        plateNumber: _plateController.text.trim(),
        token: widget.token,
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Profile updated successfully.'),
            backgroundColor: AppColors.statusDeliveredText,
          ),
        );
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString().replaceAll('Exception: ', '')),
            backgroundColor: Colors.red,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Edit Profile',
          style: TextStyle(color: AppColors.textMain, fontWeight: FontWeight.bold),
        ),
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.red)),
              const SizedBox(height: 16),
              ElevatedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Profile summary
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [AppColors.primary, Color(0xFF006064)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 56,
                        height: 56,
                        decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle),
                        child: const Icon(Icons.person, color: AppColors.primary, size: 32),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _profile['username']?.toString() ?? 'Rider',
                              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 17),
                            ),
                            Text(
                              _profile['email']?.toString() ?? '',
                              style: const TextStyle(color: Colors.white70, fontSize: 13),
                            ),
                            Text(
                              'Driver ID: #${_profile['rider_id'] ?? '--'}',
                              style: const TextStyle(color: Colors.white70, fontSize: 12),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // Vehicle info card
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.cardBg,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: Colors.grey.shade200),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Vehicle Information',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.textMain),
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _vehicleController,
                    decoration: const InputDecoration(
                      labelText: 'Vehicle Type',
                      hintText: 'e.g. Motorcycle, Bicycle',
                      prefixIcon: Icon(Icons.two_wheeler, color: AppColors.primary),
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _plateController,
                    decoration: const InputDecoration(
                      labelText: 'Plate Number',
                      hintText: 'e.g. ABC1234',
                      prefixIcon: Icon(Icons.confirmation_number, color: AppColors.primary),
                      border: OutlineInputBorder(),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Text('Save Profile', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            ),
          ],
        ),
      ),
    );
  }
}

