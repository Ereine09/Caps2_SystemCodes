# TODO - Bank payments + e-receipt + sidebar color change

- [x] Update admin sidebar purple to dark tone.
- [x] Update customer sidebar purple references to dark tone.

## Bank payments + e-receipt (customer/checkout.php)
- [ ] Add payment option: Bank Transfer.
- [ ] Add inputs: bank name/account name, transaction/reference number, proof upload.
- [ ] Update DB schema: extend tbl_orders.payment_method to include 'bank' and add proof/reference columns.
- [ ] Save bank payment details + uploaded proof to order.
- [ ] Generate e-receipt content and email it to the customer using notifications_send_email() only after payment is settled.
- [ ] Update admin/staff side (if needed) to mark bank payment as verified/settled (depends on existing verification flow).



