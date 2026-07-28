# Fix: Loyalty Points Not Adding on Checkout

## Steps

- [x] Analyze the codebase and identify root cause
- [x] Get user approval on the plan

### 1. Fix `customer/includes/functions.php` 
- [x] Add schema migration in `ensure_customer_tables()` to:
  - Alter `loyalty_transactions.user_id` to be NULLable
  - Add `order_id` column if missing

### 2. Fix `customer/checkout.php`
- [x] Add error handling/logging around the `loyalty_transactions` INSERT

### 3. Fix `customer/order_create_api.php`
- [x] Fix the `user_id` issue in its points INSERT

### 4. Test
- [ ] Verify the changes work correctly

