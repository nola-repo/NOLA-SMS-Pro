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
        return $this->forwardToLegacy(base_path('../api/credits.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function getCreditTransactions(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/get_credit_transactions.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function messages(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/messages.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function contacts(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/contacts.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function conversations(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/conversations.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function templates(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/templates.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function notificationSettings(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/notification-settings.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function account(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/account.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function accountSender(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/account-sender.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function senderRequests(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/sender-requests.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function getSenderConfig(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/get_sender_config.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function checkMessageStatus(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/check_message_status.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function checkPending(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/check_pending.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    private function forwardToLegacy(string $script, string $method, array $query = [], string $rawBody = ''): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody);

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }
}
