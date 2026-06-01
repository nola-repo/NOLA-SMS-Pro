# How to Connect NOLA SMS Pro to GoHighLevel

The 401 Unauthorized errors occur because the app needs two things to communicate with GHL: a **Location ID** and an **API Connection**.

Follow these steps to resolve this:

### Step 1: Open the App inside GoHighLevel
The app currently says "Detected GHL Location URL Param: null". This means it doesn't know which subaccount you are using.

1. Log in to your GoHighLevel Subaccount.
2. Go to **Settings > Custom Menu Links**.
3. Ensure you have a link pointing to your production URL: `https://nola-sms-pro-frontend-116662437564.asia-southeast1.run.app`
4. **Open the app only through this sidebar link inside GHL.** This allows the app to automatically detect your `location_id`.

### Step 2: Authorize the API
Once the app is open inside GHL:

1. Go to the **Settings** tab within NOLA SMS Pro.
2. Under **"GHL Integration & API"**, you should now see your Location ID detected.
3. Click the blue button: **Connect API with GoHighLevel**.
4. You will be redirected to the GHL Marketplace to authorize the app.
5. Select your subaccount and confirm.

### Step 3: Verify Connection
After authorization, you will be redirected back to the app.

1. In **Settings**, you should see a green badge saying **"API Connected"**.
2. Your contacts should now load without 401 errors.

> [!IMPORTANT]
> **To prevent "Location Not Detected" errors:**
> In your GoHighLevel account, go to **Settings > Custom Menu Links**.
> Find your NOLA SMS Pro link and ensure you check the box that says:
> **"Pass contact/user info as query parameters"**.
> This allows GHL to send the `location_id` to the app automatically.

---

> [!TIP]
> **Testing Locally:**
> If you are testing locally (`localhost:5173`), you can manually append your location ID to the URL for testing:
> `http://localhost:5173/#/settings?location=YOUR_LOCATION_ID_HERE`
