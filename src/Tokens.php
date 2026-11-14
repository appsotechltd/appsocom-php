<?php

declare(strict_types=1);

namespace SfuKit;

/** Offline join-token signing (same output as POST /tokens: HS256, audience "sfukit"). */
final class Tokens
{
    public const ID = '/^[A-Za-z0-9._:@-]{1,128}$/';
    private const ROLE = '/^[A-Za-z0-9._-]{1,32}$/';
    private const ROLE_DEFAULTS = [
        'teacher' => ['mic' => true, 'camera' => true, 'screen' => true, 'moderate' => true],
        'student' => ['mic' => true, 'camera' => true, 'screen' => false, 'moderate' => false],
        'observer' => ['mic' => false, 'camera' => false, 'screen' => false, 'moderate' => false],
    ];

    /**
     * @param array{name?:string, role?:string, permissions?:array<string,bool>, canPublish?:bool,
     *              metadata?:array<string,mixed>, maxParticipants?:int, ttlSeconds?:int,
     *              dataSaver?:string} $options
     * @return array{token:string, expiresAt:int}
     */
    public static function sign(string $jwtSecret, string $roomId, string $userId, array $options = []): array
    {
        if (strlen($jwtSecret) < 32) {
            throw new \InvalidArgumentException('jwtSecret must be at least 32 characters');
        }
        if (!preg_match(self::ID, $roomId) || !preg_match(self::ID, $userId)) {
            throw new \InvalidArgumentException('roomId and userId must be 1-128 chars of letters, numbers, . _ : @ -');
        }
        $role = $options['role'] ?? null;
        if ($role !== null && !preg_match(self::ROLE, $role)) {
            throw new \InvalidArgumentException('role must be 1-32 chars of letters, numbers, . _ -');
        }
        $pub = $options['canPublish'] ?? true;
        $perm = self::ROLE_DEFAULTS[$role ?? ''] ?? ['mic' => $pub, 'camera' => $pub, 'screen' => $pub, 'moderate' => false];
        foreach (['mic', 'camera', 'screen', 'moderate'] as $k) {
            if (is_bool($options['permissions'][$k] ?? null)) {
                $perm[$k] = $options['permissions'][$k];
            }
        }
        $md = null;
        if (array_key_exists('metadata', $options)) {
            $md = $options['metadata'];
            if (!is_array($md) || ($md !== [] && array_is_list($md)) || strlen(Json::encode((object) $md)) > 4096) {
                throw new \InvalidArgumentException('metadata must be an associative array of at most 4 KB as JSON');
            }
        }
        $max = $options['maxParticipants'] ?? null;
        if ($max !== null && (!is_int($max) || $max < 2 || $max > 10000)) {
            throw new \InvalidArgumentException('maxParticipants must be an integer 2-10000');
        }
        $ds = $options['dataSaver'] ?? null;
        if ($ds !== null && !in_array($ds, ['off', 'low', 'minimal', 'audio'], true)) {
            throw new \InvalidArgumentException('dataSaver must be "off", "low", "minimal" or "audio"');
        }
        $ttl = min(max($options['ttlSeconds'] ?? 7200, 60), 86400);
        $now = time();
        $exp = $now + $ttl;
        $name = $options['name'] ?? $userId;
        $payload = [
            'room' => $roomId,
            'name' => mb_substr($name, 0, 80),
            'pub' => $perm['mic'] || $perm['camera'] || $perm['screen'],
            'perm' => $perm,
        ];
        if ($role !== null && $role !== '') {
            $payload['role'] = $role;
        }
        if ($max !== null) {
            $payload['max'] = $max;
        }
        if ($md !== null) {
            $payload['md'] = (object) $md;
        }
        if ($ds !== null && $ds !== 'off') {
            $payload['ds'] = $ds;
        }
        $payload += ['sub' => $userId, 'aud' => 'sfukit', 'iat' => $now, 'exp' => $exp];
        $input = self::b64url(Json::encode(['alg' => 'HS256', 'typ' => 'JWT'])) . '.' . self::b64url(Json::encode($payload));
        $sig = self::b64url(hash_hmac('sha256', $input, $jwtSecret, true));
        return ['token' => "$input.$sig", 'expiresAt' => $exp];
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
