<?php

use App\Http\Controllers\AuthV2Controller;
use App\Http\Controllers\AuthV2LegacyBridgeController;
use App\Http\Controllers\AgencyV2LegacyBridgeController;
use App\Http\Controllers\BillingV2LegacyBridgeController;
use App\Http\Controllers\ProductV2LegacyBridgeController;
use App\Http\Controllers\WebhookV2LegacyBridgeController;
use App\Http\Controllers\AdminV2LegacyBridgeController;
use App\Http\Controllers\GhlV2LegacyBridgeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/v2/health', function () {
    return response()->json([
        'ok' => true,
        'source' => 'laravel',
    ]);
});

Route::post('/api/v2/auth/login', [AuthV2Controller::class, 'login']);
Route::get('/api/v2/auth/me', [AuthV2Controller::class, 'me']);
Route::post('/api/v2/auth/register', [AuthV2LegacyBridgeController::class, 'register']);
Route::post('/api/v2/auth/register-from-install', [AuthV2LegacyBridgeController::class, 'registerFromInstall']);
Route::get('/api/v2/auth/verify-install-token', [AuthV2LegacyBridgeController::class, 'verifyInstallToken']);
Route::match(['get', 'post'], '/api/v2/auth/ghl_autologin', [AuthV2LegacyBridgeController::class, 'ghlAutologin']);
Route::get('/api/v2/location/bootstrap', [AuthV2LegacyBridgeController::class, 'locationBootstrap']);

// Agency Install Trio
Route::match(['get', 'post'], '/api/v2/agency/install/provision', [AgencyV2LegacyBridgeController::class, 'provision']);
Route::get('/api/v2/agency/install/status', [AgencyV2LegacyBridgeController::class, 'status']);
Route::get('/api/v2/agency/locations', [AgencyV2LegacyBridgeController::class, 'locations']);

// Remaining Agency Routes
Route::get('/api/v2/agency/check_installs', [AgencyV2LegacyBridgeController::class, 'checkInstalls']);
Route::get('/api/v2/agency/get_all_active', [AgencyV2LegacyBridgeController::class, 'getAllActive']);
Route::get('/api/v2/agency/get_subaccounts', [AgencyV2LegacyBridgeController::class, 'getSubaccounts']);
Route::get('/api/v2/agency/profile', [AgencyV2LegacyBridgeController::class, 'profile']);
Route::match(['get', 'post', 'options'], '/api/v2/agency/ghl_agency_oauth', [AgencyV2LegacyBridgeController::class, 'ghlAgencyOauth']);
Route::post('/api/v2/agency/ghl_autologin', [AgencyV2LegacyBridgeController::class, 'ghlAutologin']);
Route::match(['post', 'options'], '/api/v2/agency/ghl_sso_decrypt', [AgencyV2LegacyBridgeController::class, 'ghlSsoDecrypt']);
Route::post('/api/v2/agency/link_company', [AgencyV2LegacyBridgeController::class, 'linkCompany']);
Route::post('/api/v2/agency/reset_attempt_count', [AgencyV2LegacyBridgeController::class, 'resetAttemptCount']);
Route::patch('/api/v2/agency/set_rate_limit', [AgencyV2LegacyBridgeController::class, 'setRateLimit']);
Route::post('/api/v2/agency/sync_locations', [AgencyV2LegacyBridgeController::class, 'syncLocations']);
Route::match(['post', 'patch'], '/api/v2/agency/toggle_subaccount', [AgencyV2LegacyBridgeController::class, 'toggleSubaccount']);
Route::post('/api/v2/agency/update_subaccount', [AgencyV2LegacyBridgeController::class, 'updateSubaccount']);

// Billing Routes
Route::match(['get', 'post'], '/api/v2/billing/agency_wallet', [BillingV2LegacyBridgeController::class, 'agencyWallet']);
Route::match(['get', 'post'], '/api/v2/billing/subaccount_wallet', [BillingV2LegacyBridgeController::class, 'subaccountWallet']);
Route::get('/api/v2/billing/transactions', [BillingV2LegacyBridgeController::class, 'transactions']);
Route::get('/api/v2/billing/report', [BillingV2LegacyBridgeController::class, 'report']);
Route::match(['get', 'post'], '/api/v2/billing/credit_requests', [BillingV2LegacyBridgeController::class, 'creditRequests']);
Route::match(['get', 'post'], '/api/v2/billing/auto_recharge_cron', [BillingV2LegacyBridgeController::class, 'autoRechargeCron']);

// Product Routes
Route::any('/api/v2/credits', [ProductV2LegacyBridgeController::class, 'credits']);
Route::any('/api/v2/get_credit_transactions', [ProductV2LegacyBridgeController::class, 'getCreditTransactions']);
Route::any('/api/v2/messages', [ProductV2LegacyBridgeController::class, 'messages']);
Route::any('/api/v2/contacts', [ProductV2LegacyBridgeController::class, 'contacts']);
Route::any('/api/v2/conversations', [ProductV2LegacyBridgeController::class, 'conversations']);
Route::any('/api/v2/templates', [ProductV2LegacyBridgeController::class, 'templates']);
Route::any('/api/v2/tickets', [ProductV2LegacyBridgeController::class, 'tickets']);
Route::any('/api/v2/notification-settings', [ProductV2LegacyBridgeController::class, 'notificationSettings']);
Route::any('/api/v2/account', [ProductV2LegacyBridgeController::class, 'account']);
Route::any('/api/v2/account-sender', [ProductV2LegacyBridgeController::class, 'accountSender']);
Route::any('/api/v2/sender-requests', [ProductV2LegacyBridgeController::class, 'senderRequests']);
Route::any('/api/v2/get_sender_config', [ProductV2LegacyBridgeController::class, 'getSenderConfig']);
Route::any('/api/v2/check_message_status', [ProductV2LegacyBridgeController::class, 'checkMessageStatus']);
Route::any('/api/v2/check-message-status', [ProductV2LegacyBridgeController::class, 'checkMessageStatus']); // Aliased as check-message-status in htaccess
Route::any('/api/v2/check_pending', [ProductV2LegacyBridgeController::class, 'checkPending']);

// Webhook Routes
Route::any('/api/v2/webhook/send_sms', [WebhookV2LegacyBridgeController::class, 'sendSms']);
Route::any('/api/v2/webhook/receive_sms', [WebhookV2LegacyBridgeController::class, 'receiveSms']);
Route::any('/api/v2/webhook/receive_sms_unisms', [WebhookV2LegacyBridgeController::class, 'receiveSmsUniSms']);
Route::any('/api/v2/webhook/retrieve_status', [WebhookV2LegacyBridgeController::class, 'retrieveStatus']);
Route::any('/api/v2/webhook/debug_ghl', [WebhookV2LegacyBridgeController::class, 'debugGhl']);
Route::any('/api/v2/webhook/fetch_bulk_messages', [WebhookV2LegacyBridgeController::class, 'fetchBulkMessages']);
Route::any('/api/v2/webhook/fetch_logs', [WebhookV2LegacyBridgeController::class, 'fetchLogs']);
Route::any('/api/v2/webhook/fix_failed_statuses', [WebhookV2LegacyBridgeController::class, 'fixFailedStatuses']);
Route::any('/api/v2/webhook/fix_key', [WebhookV2LegacyBridgeController::class, 'fixKey']);
Route::any('/api/v2/webhook/ghl_create_conversation', [WebhookV2LegacyBridgeController::class, 'ghlCreateConversation']);
Route::any('/api/v2/webhook/ghl_debug', [WebhookV2LegacyBridgeController::class, 'ghlDebug']);
Route::any('/api/v2/webhook/ghl_oauth', [WebhookV2LegacyBridgeController::class, 'ghlOauth']);
Route::any('/api/v2/webhook/ghl_provider', [WebhookV2LegacyBridgeController::class, 'ghlProvider']);
Route::any('/api/v2/webhook/ghl_marketplace_events', [WebhookV2LegacyBridgeController::class, 'ghlMarketplaceEvents']);
Route::any('/api/v2/webhook/ghl_conversation_message', [WebhookV2LegacyBridgeController::class, 'ghlConversationMessage']);
Route::any('/api/v2/webhook/ghl_reconcile_conversations', [WebhookV2LegacyBridgeController::class, 'ghlReconcileConversations']);

// Admin Routes
Route::any('/api/v2/admin_auth', [AdminV2LegacyBridgeController::class, 'auth']);
Route::any('/api/v2/admin_agencies', [AdminV2LegacyBridgeController::class, 'agencies']);
Route::any('/api/v2/admin_users', [AdminV2LegacyBridgeController::class, 'users']);
Route::any('/api/v2/admin_list_agency_users', [AdminV2LegacyBridgeController::class, 'agencyUsers']);
Route::any('/api/v2/admin_settings', [AdminV2LegacyBridgeController::class, 'settings']);
Route::any('/api/v2/admin_sender_requests', [AdminV2LegacyBridgeController::class, 'senderRequests']);
Route::any('/api/v2/admin_forgot_password', [AdminV2LegacyBridgeController::class, 'forgotPassword']);
Route::any('/api/v2/admin_health', [AdminV2LegacyBridgeController::class, 'health']);
Route::any('/api/v2/seed_admin', [AdminV2LegacyBridgeController::class, 'seedAdmin']);

// GHL & Public Routes
Route::any('/api/v2/ghl/oauth_exchange', [GhlV2LegacyBridgeController::class, 'oauthExchange']);
Route::any('/api/v2/ghl_oauth', [GhlV2LegacyBridgeController::class, 'ghlOauth']);
Route::any('/api/v2/ghl_contacts', [GhlV2LegacyBridgeController::class, 'ghlContacts']);
Route::any('/api/v2/ghl-contacts', [GhlV2LegacyBridgeController::class, 'ghlContacts']); // Alias for .htaccess rule
Route::any('/api/v2/ghl-conversations', [GhlV2LegacyBridgeController::class, 'ghlConversations']);
Route::any('/api/v2/public/whitelabel', [GhlV2LegacyBridgeController::class, 'whitelabel']);
