<?php

namespace App\Http\Controllers;

use App\Services\LegacyPhpBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookV2LegacyBridgeController extends Controller
{
    public function __construct(private readonly LegacyPhpBridgeService $bridge)
    {
    }

    public function sendSms(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/send_sms.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function receiveSms(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/receive_sms.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function retrieveStatus(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/retrieve_status.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function debugGhl(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/debug_ghl.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function fetchBulkMessages(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/fetch_bulk_messages.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function fetchLogs(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/fetch_logs.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function fixFailedStatuses(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/fix_failed_statuses.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function fixKey(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/fix_key.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlCreateConversation(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/ghl_create_conversation.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlDebug(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/ghl_debug.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlOauth(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/ghl_oauth.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlProvider(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/ghl_provider.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlMarketplaceEvents(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/ghl_marketplace_events.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlConversationMessage(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/ghl_conversation_message.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlReconcileConversations(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/webhook/ghl_reconcile_conversations.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    private function forwardToLegacy(string $script, string $method, array $query = [], string $rawBody = ''): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody);

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }
}
