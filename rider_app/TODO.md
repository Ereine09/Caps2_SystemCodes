# Rider App — Remove "Coming Soon" Placeholders

## Goal
Replace the remaining "coming soon" snackbar placeholders in the rider drawer with functional screens.

## Steps
- [x] 1. Create `parcel_transfer_screen.dart` — lists active deliveries with a transfer action UI (uses real delivery data).
- [x] 2. Create `learning_center_screen.dart` — self-contained educational/onboarding content.
- [x] 3. Create `help_center_screen.dart` — FAQ accordion + contact info.
- [x] 4. Create `ticket_center_screen.dart` — support ticket form with in-session list.
- [x] 5. Wire all four screens into `rider_drawer.dart`, removing the `_showComingSoon` calls for these items.
- [x] 6. Run `flutter analyze` to confirm 0 errors. (15 issues, 0 errors — all pre-existing `withOpacity` deprecations + 1 unused const; none from new code)
