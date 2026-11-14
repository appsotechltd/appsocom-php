<?php

declare(strict_types=1);

namespace SfuKit;

/** Webhook signature checks (header X-SfuKit-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "t.body">). */
final class Webhooks
{
    public const SIGNATURE_HEADER = 'X-SfuKit-Signature';

    /**
     * Check a delivery. Pass the RAW body (file_get_contents('php://input')) and the header
     * ($_SERVER['HTTP_X_SFUKIT_SIGNATURE']). $secret is WEBHOOK_SECRET (or ADMIN_API_KEY).
     * Returns the event as an array, or null if the signature is wrong or too old.
     *
     * @return array<string,mixed>|null
     */
    public static function verify(string $secret, string $rawBody, ?string $signatureHeader, int $toleranceSeconds = 300): ?array
    {
        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return null;
        }
        $parts = [];
        foreach (explode(',', $signatureHeader) as $p) {
            $kv = explode('=', $p, 2);
            $parts[trim($kv[0])] = trim($kv[1] ?? '');
        }
        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if (!ctype_digit($t) || !preg_match('/^[0-9a-f]{64}$/', $v1) || abs(time() - (int) $t) > $toleranceSeconds) {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', "$t.$rawBody", $secret), $v1)) {
            return null;
        }
        try {
            $event = Json::decode($rawBody);
        } catch (\JsonException) {
            return null;
        }
        return is_array($event) ? $event : null;
    }

    /** Build a signature header (for tests of your webhook endpoint). */
    public static function sign(string $secret, string $rawBody, ?int $t = null): string
    {
        $t ??= time();
        return "t=$t,v1=" . hash_hmac('sha256', "$t.$rawBody", $secret);
    }
}
