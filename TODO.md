# Task: Fix MySQL Database Connection Error

**Problem:** `http://localhost/loyalty_managements/customer/notifications.php` fails with "Database Connection Error - target machine actively refused."
**Root Cause:** MySQL data directory corrupted; `mysqld` crashes during startup (InnoDB LSN mismatch + crash on `mysql.user`).

## Steps
- [x] 1. Back up corrupt data directory
- [x] 2. Reinitialize fresh MySQL data directory
- [x] 3. Start MySQL via `mysql_start.bat`
- [x] 4. Verify MySQL is running (port 3306 listening)
- [x] 5. Import `capstone_db (1).sql` backup
- [x] 6. Verify `capstone_db` database and `notifications` table exist
- [x] 7. Test `customer/notifications.php` page (DB connection verified)
