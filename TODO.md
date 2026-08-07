# Rider App — Teal Delivery Dashboard + Drawer Redesign

## Goal
Reorganize the existing rider app layout (teal palette, all features intact) to mirror the SPX-style delivery dashboard + side drawer.

## Steps
- [x] 1. Update `AppColors` (colors.dart) — add supporting grey/teal-tint shades; keep existing teal primary.
- [x] 2. Create `rider_app/lib/src/ui/rider_drawer.dart` — profile header (avatar, name, rating, driver ID), feature list (Queue Status, Check In, Drive Report, Order Statistics, Performance, Parcel Transfer, Learning Center, Help Center, Settings), bottom action grid (Ticket Center, Upload ePOD w/ badge).
- [x] 3. Create `rider_app/lib/src/ui/rider_delivery_dashboard.dart` — header (hamburger, Delivery label, search, notification/chat icons w/ badges, on-duty toggle), status tabs (To-do/Delivered/On-Hold) w/ teal underline + counts, map banner + sort/priority pills, reorganized parcel cards (tracking + copy, pin + address + recipient + status, call/chat), teal QR FAB.
- [x] 4. Wire dashboard into `home_screen.dart` (replace body, keep on-duty + notification logic, add drawer; keep bottom nav).
- [x] 5. Run `flutter analyze` (0 new errors; only pre-existing `withOpacity` deprecation infos remain).
