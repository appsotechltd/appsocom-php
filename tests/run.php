<?php

// Unit tests without PHPUnit: php tests/run.php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use SfuKit\SfuKit;
use SfuKit\SfuKitError;
use SfuKit\Tokens;
use SfuKit\Webhooks;

$fail = 0;
function ok(bool $cond, string $name): void
{
    global $fail;
    echo ($cond ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$cond) {
        $fail++;
    }
}
function b64d(string $s): array
{
    return json_decode(base64_decode(strtr($s, '-_', '+/')), true);
}

$secret = str_repeat('x', 40);
$t = Tokens::sign($secret, 'r1', 'u1', ['role' => 'student', 'metadata' => ['seat' => 3], 'permissions' => ['screen' => true]]);
[$h, $b, $s] = explode('.', $t['token']);
$claims = b64d($b);
ok(b64d($h) === ['alg' => 'HS256', 'typ' => 'JWT'], 'token header');
ok($claims['sub'] === 'u1' && $claims['room'] === 'r1' && $claims['aud'] === 'sfukit' && $claims['role'] === 'student', 'token claims');
ok($claims['md'] === ['seat' => 3], 'token metadata');
ok($claims['perm'] === ['mic' => true, 'camera' => true, 'screen' => true, 'moderate' => false], 'role defaults + override');
ok($claims['exp'] - $claims['iat'] === 7200, 'default ttl');
ok(rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$b", $secret, true)), '+/', '-_'), '=') === $s, 'HS256 signature');
ok(str_contains(base64_decode(strtr(explode('.', Tokens::sign($secret, 'r1', 'u1', ['metadata' => []])['token'])[1], '-_', '+/')), '"md":{}'), 'empty metadata is an object');
foreach ([['short', 'r1', 'u1', []], [$secret, 'bad room', 'u1', []], [$secret, 'r1', 'u1', ['maxParticipants' => 1]]] as $args) {
    try {
        Tokens::sign(...$args);
        ok(false, 'rejects bad token input');
    } catch (\InvalidArgumentException) {
        ok(true, 'rejects bad token input');
    }
}

$body = '{"id":"evt_1","event":"room_started"}';
$sig = Webhooks::sign('s', $body);
ok((Webhooks::verify('s', $body, $sig)['id'] ?? null) === 'evt_1', 'webhook verifies');
ok(Webhooks::verify('s', $body . ' ', $sig) === null, 'webhook rejects changed body');
ok(Webhooks::verify('other', $body, $sig) === null, 'webhook rejects wrong secret');
ok(Webhooks::verify('s', $body, Webhooks::sign('s', $body, time() - 600)) === null, 'webhook rejects old signature');
ok(Webhooks::verify('s', $body, null) === null, 'webhook rejects missing header');

$kit = new SfuKit('http://127.0.0.1:1/', 'k');
try {
    $kit->getRoom('bad room!');
    ok(false, 'invalid id throws');
} catch (SfuKitError $e) {
    ok($e->status === 400, 'invalid id throws 400');
}
try {
    $kit->getRoom('r1');
    ok(false, 'unreachable server throws');
} catch (SfuKitError $e) {
    ok($e->status === 0, 'unreachable server throws status 0');
}
$local = new SfuKit('http://127.0.0.1:1', 'k', $secret);
ok(b64d(explode('.', $local->createToken('r1', 'u9')['token'])[1])['sub'] === 'u9', 'createToken signs locally with jwtSecret');

ok(b64d(explode('.', Tokens::sign($secret, 'r1', 'u1', ['dataSaver' => 'minimal'])['token'])[1])['ds'] === 'minimal', 'dataSaver goes in the token as ds');
ok(!isset(b64d(explode('.', Tokens::sign($secret, 'r1', 'u1', ['dataSaver' => 'off'])['token'])[1])['ds']), 'dataSaver off adds no claim');
try {
    Tokens::sign($secret, 'r1', 'u1', ['dataSaver' => 'ultra']);
    ok(false, 'bad dataSaver throws');
} catch (\InvalidArgumentException $e) {
    ok(true, 'bad dataSaver throws');
}

echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
