<?php

namespace App\Http\Controllers;

use App\Services\LegacyPhpBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProductV2LegacyBridgeController extends Controller
{
    public function __construct(private readonly LegacyPhpBridgeService $bridge)
    {
    }

    public function credits(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/billing/credits.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function getCreditTransactions(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/billing/get_credit_transactions.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function messages(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/messaging/messages.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function contacts(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/messaging/contacts.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function conversations(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/messaging/conversations.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function templates(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/messaging/templates.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function tickets(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/tickets/tickets.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function notificationSettings(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/notifications/notification-settings.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function account(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/account/account.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function accountSender(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/account/account-sender.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function senderRequests(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/sender/sender-requests.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function getSenderConfig(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/sender/get_sender_config.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function checkMessageStatus(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/messaging/check_message_status.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function checkPending(Request $request): Response
    {
        return response(['error' => 'check_pending is not available in production.'], 404)
            ->header('Content-Type', 'application/json');
    }

    private function forwardToLegacy(string $script, string $method, array $query = [], string $rawBody = ''): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody);

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }
}
