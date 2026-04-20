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
- [ ] Payment webhook — credit subaccount wallet on purchase

---

## ⚙️ Infra / Core
- [ ] `api/billing/webhook_payment.php` — new file for payment provider callbacks
- [ ] Firestore indexes — `credit_transactions` + `users.role`
- [ ] Pricing config — move provider_cost / charged out of hardcode in `send_sms.php`
- [ ] GCP Cloud Scheduler job registration
