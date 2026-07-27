# Loyalty Points "Stuck at 53" Bug Fix

## Problem
Points added via the staff "Manage Customer Points" panel always result in the balance staying at 53. The `notifications_sync_customer_loyalty_points()` function's "legacy buffer" logic absorbs newly added points.

## Root Cause
The `notifications_sync_customer_loyalty_points()` in `notification_helper.php` calculates:
- `computed_points` = SUM from `loyalty_transactions`
- `current_points` = current value from `customers.loyalty_points`
- `legacy_buffer` = max(0, current - computed)

When staff adds +5 points:
1. A `loyalty_transactions` record is inserted (+5)
2. Sync function runs: computed=5, current=53 (old value), legacy=48
3. `new_total = 5 + 48 = 53` — **points stay at 53!**

The same thing happens with order-created points.

## Fix (2 files changed)

### 1. `app/helpers/notification_helper.php`
- Remove the `legacy_buffer` logic entirely
- Simply compute: `new_points = SUM(earned) - SUM(redeemed)`
- Single source of truth = transaction tables

### 2. `customer/includes/functions.php`
- Remove the direct `UPDATE customers SET loyalty_points = loyalty_points + $loyalty_points` in `create_customer_order()`
- The sync function (called after) will properly reflect the balance from transactions
- ✅ Status: DONE ✅

## Verification
- After fix, points should accurately reflect: SUM(transactions) - SUM(redemptions)
- Staff additions +5 → balance increases by exactly +5
- Order purchases → points earned correctly added
- Redemptions → points deducted correctly
- ✅ Status: ALL FIXES APPLIED ✅

## Verification
- After fix, points should accurately reflect: SUM(transactions) - SUM(redemptions)
- Staff additions +5 → balance increases by exactly +5
- Order purchases → points earned correctly added
- Redemptions → points deducted correctly
- ✅ Status: PENDING

