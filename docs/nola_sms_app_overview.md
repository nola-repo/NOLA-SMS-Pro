# NOLA SMS Pro PH — Flutter Frontend Overview

## 🧭 What is this app?

**NOLA SMS Pro PH** is a Flutter mobile app that lets users send and manage SMS messages, integrated with **GoHighLevel (GHL)** — a CRM platform. The app is designed for Philippine businesses and supports individual and bulk SMS, contact management, and a credit-based billing system.

---

## 🗂️ Project Structure

```
lib/
├── main.dart              # App entry point & routing
├── screens/               # All UI screens (14 screens)
├── services/              # API, auth, and config layer
├── theme/                 # Centralized design tokens & ThemeData
├── widgets/               # Reusable UI components
├── utils/                 # Helper utilities
└── data/                  # Local data / state (message store)
```

---

## 📱 Screens

| Screen | Route | Purpose |
|---|---|---|
| `SplashScreen` | `/splash` | Entry splash / loading |
| `LoginScreen` | `/login` | Email & password login |
| `RegisterScreen` | `/register` | New user registration |
| `VerifyEmailScreen` | `/verify-email` | Email verification gate |
| `MainScreen` | `/main` | Main shell/wrapper |
| `InboxScreen` | `/inbox` | SMS conversation list |
| `ChatScreen` | `/chat` | Individual SMS conversation thread |
| `ComposeMessageScreen` | `/compose` | Compose new/bulk SMS |
| `ContactScreen` | `/contacts` | GHL contact browser |
| `ConnectGhlScreen` | `/connect-ghl` | Link GHL account (API token) |
| `GhlInstructionsScreen` | `/ghl-instructions` | Step-by-step GHL setup guide |
| `ProfileScreen` | `/profile` | User profile info |
| `SettingsScreen` | `/settings` | App settings |
| `TopupScreen` | *(pushed directly)* | Buy SMS credits |

---

## 🔄 Navigation & Routing

- Uses `MaterialApp` with **named routes** via `onGenerateRoute`
- A global `navKey` (`GlobalKey<NavigatorState>`) allows navigation from outside the widget tree (e.g., from services)
- `/chat` receives rich arguments: `userName`, `locationId`, `contactId`, `phone`, `conversationId`, `isBulk`, `bulkMembers`, etc.
- Default fallback route → `LoginScreen`

---

## ⚙️ Services Layer

### `AuthService` (`auth_service.dart`)
- Wraps **Firebase Auth** (email/password)
- Stores user profile in **Cloud Firestore** (`users/{uid}`)
- Handles: login, register, email verification, resend verification, logout
- All calls wrapped in timeout helpers (12–20s)

### `SmsApi` (`sms_api.dart`)
- HTTP `POST` to Cloud Run backend (`asia-southeast1`)
- Sends **individual** and **bulk** SMS
- Payload includes: number, message, sender name, locationId, contactId, ownerUid, conversationId, isBulk flag, bulkMembers list

### `CreditsApi` (`credits_api.dart`)
- Fetches credit balance per GHL `locationId`
- Supports credit deduction after send
- Returns balance, free usage count, currency (PHP)
- Provides static **credit packages** (10 / 500 / 1100 / 2750 / 6000 credits)
- Builds top-up URLs with `location_id` query param

### `AppConfig` (`app_config.dart`)
- Central config file for all **backend URLs**
- Three Cloud Run services:
  - `nola-mobile-backend` (US Central) — auth, user data
  - `nola-backend-cloud` (Asia SE1) — SMS send, GHL contacts
  - `sms-api` (Asia SE1) — credits
- GHL OAuth callback: `https://nolasmspro.com/oauth-callback`

---

## 🎨 Theme & Design

File: `lib/theme/App_theme.dart`

- **Dark mode** first: Background `#050B18` (near-black navy)
- **Blue accent palette:**
  - Primary Blue: `#2553A0`
  - Accent Blue: `#188BF6`
  - Secondary Blue: `#4085F2`
- **Text opacity hierarchy:** Strong (95%) → Soft (72%) → Muted (48%) → Faint (24%)
- **Gradients:**
  - Header: `#1E3A8A → #2563EB → #3B82F6`
  - Bottom Nav: `#0B1225 → #1E3A8A → #2563EB`
- Material 3 (`useMaterial3: true`)
- Rounded inputs with no border, focused border uses `accentBlue`

---

## 🧩 Reusable Widgets

| Widget | Purpose |
|---|---|
| `BottomNavBar` | Persistent bottom navigation |
| `Header` | App bar / top header with NOLA branding |
| `LogoutButton` | Reusable logout trigger |
| `ProfileHeader` | User avatar + name display |
| `UiDialogs` | Shared dialog/snackbar helpers |

---

## 🛠️ Utilities

| File | Purpose |
|---|---|
| `conversation_utils.dart` | Build/merge conversation IDs |
| `message_utils.dart` | Message formatting helpers |
| `security_utils.dart` | Input sanitization / security |
| `sms_credit_calculator.dart` | Calculate SMS segment count & credit cost |

---

## 📦 Key Dependencies

| Package | Use |
|---|---|
| `firebase_auth` | User authentication |
| `cloud_firestore` | User profile storage |
| `firebase_core` | Firebase initialization |
| `http` | REST API calls |
| `flutter_secure_storage` | Secure local key/token storage |
| `shared_preferences` | Lightweight local preferences |
| `flutter_inappwebview` | Embedded webview (GHL OAuth) |
| `flutter_web_auth_2` | OAuth2 flow helper |
| `webview_flutter` | Secondary webview support |
| `url_launcher` | Open top-up and external links |
| `intl` | Date/time formatting |

---

## 🔗 Backend Architecture (from frontend's perspective)

```
Flutter App
│
├── Firebase Auth  ──────────────────────────► Firebase (Google)
├── Cloud Firestore ─────────────────────────► Firebase (Google)
│
├── Mobile Backend (US Central)
│   └── /api/get_user_data.php
│   └── /api/login.php
│   └── /api/register.php
│   └── /api/deduct_credits.php
│
├── Main Backend (Asia SE1)
│   └── /api/send_sms.php
│   └── /api/get_conversation_by_phone.php
│   └── /api/merge_duplicate_conversations.php
│   └── /api/get_ghl_contacts.php
│   └── /api/save_ghl_connection.php
│
└── SMS API (Asia SE1)
    └── /api/credits.php
```

---

## 🏁 App Launch Flow

```
SplashScreen
    │
    ├─ Not logged in ──► LoginScreen ──► RegisterScreen ──► VerifyEmailScreen
    │
    └─ Logged in ──► MainScreen
                        │
                        ├── InboxScreen ──► ChatScreen
                        ├── ContactScreen ──► ChatScreen (with contact)
                        ├── ComposeMessageScreen ──► ChatScreen (bulk)
                        ├── ConnectGhlScreen ──► GhlInstructionsScreen
                        ├── ProfileScreen
                        ├── SettingsScreen
                        └── TopupScreen
```
