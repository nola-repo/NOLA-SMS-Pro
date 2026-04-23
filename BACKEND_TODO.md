# BACKEND TODO

## 🚀 Completed Tasks
- [x] **Dynamic Pricing Isolation**: Separated platform settings and pricing data in `api/admin_settings.php`.
- [x] **Pricing Persistence**: Linked `admin_settings.php` to `admin_config/global_pricing` collection for compatibility with `CreditManager.php`.
- [x] **Frontend Bridge**: Mapped `charged_rate` to Firestore `charged` field for seamless UI integration.

## 🛠️ Pending Tasks
- [ ] **Admin User Management**: Complete CRUD for `admins` collection in `api/admin_users.php`.
- [ ] **Agency Wallet "Gift" Logic**: Verify atomic transactions in `api/billing/agency_wallet.php`.
- [ ] **CORS Audit**: Finalize centralization of CORS headers across all endpoints.
- [ ] **GHL Integration**: Ensure `ghl_tokens` collection is correctly utilized for all location-specific API calls.
