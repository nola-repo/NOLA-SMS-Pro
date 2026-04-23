# POSTMAN COLLECTIONS

## ⚙️ Admin Settings API
**Endpoint**: `/api/admin_settings.php`  
**Base URL**: `{{baseUrl}}`

### 1. Get All Settings
- **Method**: `GET`
- **Description**: Retrieves both platform configuration and dynamic pricing.
- **Expected Data Response**:
  ```json
  {
    "status": "success",
    "data": {
      "sender_default": "NOLASMSPro",
      "free_limit": 10,
      "maintenance_mode": false,
      "poll_interval": 15,
      "provider_cost": 0.02,
      "charged_rate": 0.05
    }
  }
  ```

### 2. Update Pricing Only
- **Method**: `POST`
- **Body (JSON)**:
  ```json
  {
    "provider_cost": 0.025,
    "charged_rate": 0.065
  }
  ```

### 3. Update Platform Settings Only
- **Method**: `POST`
- **Body (JSON)**:
  ```json
  {
    "free_limit": 20,
    "maintenance_mode": true
  }
  ```

---

## 🔑 Authentication
Ensure the following headers are included for all administrative requests:
- `X-Webhook-Secret`: `{{WEBHOOK_SECRET}}`
- `Content-Type`: `application/json`
