<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/services/FirestoreId.php';
require_once __DIR__ . '/../../../api/services/PhoneNormalizer.php';
require_once __DIR__ . '/../../../api/services/ProviderResultService.php';
require_once __DIR__ . '/../../../api/services/GhlTokenProvider.php';
require_once __DIR__ . '/../../../api/services/ApiValueFormatter.php';
require_once __DIR__ . '/../../../api/services/LocationUserResolver.php';
require_once __DIR__ . '/../../../api/logger.php';

class BackendHardeningServicesTest extends TestCase
{
    public function test_phone_normalizer_deduplicates_philippine_mobile_numbers(): void
    {
        $numbers = \PhoneNormalizer::philippineMobiles([
            '+639171234567',
            '0917 123 4567',
            '917-123-4567',
            '+1 555 0100',
        ]);

        $this->assertSame(['09171234567'], $numbers);
    }

    public function test_firestore_sms_log_id_is_stable_and_safe(): void
    {
        $id = \FirestoreId::smsLogId('loc/1', 'UniSMS', 'msg 123', '+639171234567', 'batch#1');

        $this->assertSame('sms_loc_1_UniSMS__639171234567_batch_1_msg_123', $id);
    }

    public function test_provider_validation_allows_short_unisms_messages(): void
    {
        $result = \ProviderResultService::providerMessageValidation('unisms_custom', 'hello');

        $this->assertNull($result);
    }

    public function test_provider_summary_maps_gateway_failure_to_public_status(): void
    {
        $summary = \ProviderResultService::summarizeGatewayResults([
            [
                'message_id' => 'failed_1234',
                'status' => 'failed',
                'error' => 'UniSMS send failed (HTTP 504): timeout',
            ],
        ]);

        $this->assertSame(['UniSMS send failed (HTTP 504): timeout'], $summary['errors']);
        $this->assertSame(504, $summary['provider_http_status']);
        $this->assertSame(504, $summary['http_status']);
    }

    public function test_gateway_acceptance_requires_saved_non_failed_message(): void
    {
        $accepted = \ProviderResultService::accepted(
            [['message_id' => 'failed_1234', 'status' => 'failed']],
            ['failed_1234'],
            200,
            []
        );

        $this->assertFalse($accepted);

        $accepted = \ProviderResultService::accepted(
            [['message_id' => 'abc', 'status' => 'queued']],
            ['abc'],
            200,
            []
        );

        $this->assertTrue($accepted);
    }

    public function test_ghl_oauth_refresh_401_requires_reconnect(): void
    {
        $this->assertSame(
            \GhlOAuthRefreshException::REASON_INVALID_GRANT,
            \GhlTokenProvider::classifyOAuthRefreshFailure(401, ['error' => 'unauthorized'])
        );

        $this->assertSame(
            \GhlOAuthRefreshException::REASON_TRANSIENT,
            \GhlTokenProvider::classifyOAuthRefreshFailure(503, ['error' => 'temporarily_unavailable'])
        );
    }

    public function test_api_value_formatter_accepts_mixed_timestamp_values(): void
    {
        $date = new \DateTimeImmutable('2026-06-22T08:30:00+00:00');

        $this->assertSame('2026-06-22T08:30:00+00:00', \ApiValueFormatter::timestamp($date));
        $this->assertSame('2026-06-22T08:30:00Z', \ApiValueFormatter::timestamp(' 2026-06-22T08:30:00Z '));
        $this->assertSame('1970-01-01T00:00:00+00:00', \ApiValueFormatter::timestamp(0));
        $this->assertNull(\ApiValueFormatter::timestamp(null));
        $this->assertNull(\ApiValueFormatter::timestamp(['not' => 'a timestamp']));
    }

    public function test_location_user_resolver_validates_location_and_deduplicates_matches(): void
    {
        $data = [
            'role' => 'user',
            'active' => true,
            'active_location_id' => 'loc-123',
            'ghl_token_ref' => 'ghl_tokens/loc-123',
        ];

        $this->assertTrue(\LocationUserResolver::isEligibleUser($data, 'loc-123'));
        $this->assertFalse(\LocationUserResolver::isEligibleUser($data, 'loc-other'));
        $this->assertFalse(\LocationUserResolver::isEligibleUser(array_merge($data, ['role' => 'agency']), 'loc-123'));
        $this->assertFalse(\LocationUserResolver::isEligibleUser(array_merge($data, ['location_id' => 'loc-other']), 'loc-123'));

        $unique = \LocationUserResolver::deduplicateMatches([
            ['id' => 'user-1', 'data' => $data],
            ['id' => 'user-1', 'data' => $data],
            ['id' => 'user-2', 'data' => $data],
        ]);
        $this->assertSame(['user-1', 'user-2'], array_keys($unique));
    }

    public function test_logger_accepts_only_bounded_safe_request_ids(): void
    {
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', \Logger::normalizeRequestId('123e4567-e89b-12d3-a456-426614174000'));
        $this->assertNull(\Logger::normalizeRequestId("bad\nheader"));
        $this->assertNull(\Logger::normalizeRequestId(str_repeat('a', 129)));
    }
}
