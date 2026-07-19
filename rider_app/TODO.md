# rider_app fix plan

## Step 1: Understand current rider app API usage
- Inspect rider_app Dart code to see which endpoints it calls and what status mapping it expects.

## Step 2: Fix rider app to use correct admin orders data
- Update rider_app order list/state code to fetch real order statuses from `modules/admin/orders.php` (or, better, from a dedicated API used by that page) instead of showing all as `pending`.

## Step 3: Fix customer checkout failure ("Unable to place your order...")
- Identify why `create_customer_order()` returns null/false during `customer/checkout.php`.
- Patch backend function(s) in `customer/checkout.php` / `customer/order_create_api.php` / `customer/includes/functions.php` so checkout succeeds consistently.

## Step 4: Add debugging + safer error handling
- When order creation fails, return/log the real underlying error message.

## Step 5: Validate end-to-end
- Test: rider app shows correct statuses.
- Test: customer checkout successfully places an order.

