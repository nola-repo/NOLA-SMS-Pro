# Backend To-Do

## 🏢 Agency
- [x] Agency wallet — GET, set_auto_recharge, set_master_lock, gift
- [x] Credit requests — GET, approve, deny
- [x] Transaction log — agency scope + month filter
- [x] Admin: GET all agency accounts
- [ ] Payment webhook — credit agency wallet on purchase
- [ ] Cloud Scheduler — register auto-recharge cron (every 15 min)

---

## 👤 User / Subaccount
- [x] Subaccount wallet — GET, set_auto_recharge, request_credits
- [x] Transaction log — subaccount scope + month filter
- [x] SMS send — single-deduction (subaccount only) + master lock gate
- [x] Fix credit deduction: Workflows & Native SMS (ghl_provider.php)
- [ ] Payment webhook — credit subaccount wallet on purchase

---

---
 
 ## ⚙️ Infra / Core
 - [ ] `api/billing/webhook_payment.php` — new file for payment provider callbacks
 - [ ] Firestore indexes — `credit_transactions` + `users.role`
 - [ ] Pricing config — move provider_cost / charged out of hardcode in `send_sms.php`
 - [ ] GCP Cloud Scheduler job registration
 - [ ] **CORS Maintenance**
     - [ ] Monitor production for "duplicate header" errors after redeploy
     - [ ] Audit all future `.php` endpoints to ensure `cors.php` is included

---

## 💬 Send SMS / GHL Sync
- [x] Improve GHL Execution Logs (status/event_details response in send_sms.php)
- [ ] Refine Native Sync metadata consistency across messages/sms_logs
- [ ] Transition remaining deprecated deduction calls to deduct_subaccount_only()
