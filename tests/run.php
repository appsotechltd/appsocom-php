<?php

// Unit tests without PHPUnit: php tests/run.php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use SfuKit\AssistantToolError;
use SfuKit\AssistantTools;
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
foreach ([['..', '20261118T073712-1a2b3c4d', 'transcript'], ['r1', '..', 'transcript'], ['r1', '20261118T073712-1a2b3c4d', '..']] as $args) {
    try {
        $kit->downloadRecording(...$args);
        ok(false, 'dot segment in a recording path throws');
    } catch (SfuKitError $e) {
        ok($e->status === 400, 'dot segment in a recording path throws 400');
    }
}
try {
    $kit->downloadRecording('r1', '20261118T073712-1a2b3c4d', '001-u1-audio.wav');
    ok(false, 'valid recording path is sent');
} catch (SfuKitError $e) {
    ok($e->status === 0, 'valid recording path is sent');
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

// ---------- AI assistant ----------

// A tool call signed by the Worker's own signWebhook (packages/server/src/webhooks.ts), shared by every SDK's tests.
$vectorBody = '{"type":"tool_call","kind":"assistant","conversationId":"c1","userId":"u_7","role":"teacher","callId":"call_1","name":"list_students","arguments":{"cls":"JSS1"},"metadata":{"school":"s1"}}';
$vectorSig = 't=1763500000,v1=6497035b3915fa61b8deb6bfa54b0f22d52ff62c71f077a2d43873d747ed24a9';
ok(AssistantTools::verify('whsec_assistant_test', $vectorBody, $vectorSig) === null, 'assistant: stale signature refused');
$call = AssistantTools::verify('whsec_assistant_test', $vectorBody, $vectorSig, PHP_INT_MAX >> 1);
ok(($call['name'] ?? null) === 'list_students' && $call['arguments'] === ['cls' => 'JSS1'] && $call['metadata'] === ['school' => 's1'], 'assistant: verifies the Worker\'s signature');
ok(AssistantTools::verify('other', $vectorBody, $vectorSig, PHP_INT_MAX >> 1) === null, 'assistant: wrong secret refused');

function toolCall(string $name, array $args = [], array $extra = []): string
{
    return json_encode($extra + ['type' => 'tool_call', 'kind' => 'assistant', 'conversationId' => 'c1', 'userId' => 'u_7', 'role' => 'teacher', 'callId' => 'call_1', 'name' => $name, 'arguments' => (object) $args, 'metadata' => ['school' => 's1']]);
}
$errors = [];
$tools = new AssistantTools('s', [
    'list_students' => fn (array $args, array $call) => [['name' => 'Ada', 'cls' => $args['cls'], 'school' => $call['metadata']['school'], 'asker' => $call['userId']]],
    'nothing' => function (array $args): void {
    },
    'boom' => function (): never {
        throw new \RuntimeException('database password is hunter2');
    },
    'denied' => function (): never {
        throw new AssistantToolError('You can only see your own classes.');
    },
], onError: function (\Throwable $e, array $call) use (&$errors): void {
    $errors[] = [$e->getMessage(), $call['name']];
});
$run = fn (string $body, string $secret = 's', ?int $t = null) => $tools->handle($body, Webhooks::sign($secret, $body, $t));
$out = $run(toolCall('list_students', ['cls' => 'JSS1']));
ok($out === [200, '{"result":[{"name":"Ada","cls":"JSS1","school":"s1","asker":"u_7"}]}'], 'assistant: runs the tool as the person');
ok($run(toolCall('nothing')) === [200, '{"result":"done"}'], 'assistant: nothing returned is "done"');
ok($run(toolCall('denied')) === [200, '{"error":"You can only see your own classes."}'], 'assistant: AssistantToolError message reaches the model');
ok($run(toolCall('boom')) === [200, '{"error":"The tool failed."}'] && $errors === [['database password is hunter2', 'boom']], 'assistant: other errors are hidden and passed to onError');
foreach (['missing', '__construct', 'handle', 'constructor'] as $n) {
    ok($run(toolCall($n)) === [200, '{"error":"There is no tool called ' . $n . '."}'], "assistant: no tool called $n");
}
$b = toolCall('list_students', ['cls' => 'JSS1']);
ok($run($b, 'other')[0] === 401, 'assistant: wrong secret is 401');
ok($run($b, 's', time() - 301)[0] === 401, 'assistant: stale signature is 401');
ok($tools->handle($b, null)[0] === 401, 'assistant: missing signature is 401');
ok($run(toolCall('list_students', [], ['kind' => 'agent']))[0] === 401, 'assistant: agent tool calls are refused');
ok($run(toolCall('list_students', [], ['type' => 'call.ended']))[0] === 401, 'assistant: other events are refused');
ok($run('not json')[0] === 401, 'assistant: non-JSON is refused');
try {
    new AssistantTools('', []);
    ok(false, 'assistant: secret required');
} catch (\InvalidArgumentException) {
    ok(true, 'assistant: secret required');
}

final class RecordingKit extends SfuKit
{
    public array $seen = [];

    public function request(string $method, string $path, mixed $body = null, bool $raw = false): mixed
    {
        $this->seen[] = [$method, $path, $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES)];
        return match (true) {
            $path === '/assistant/config' => ['config' => ['name' => 'Ada']],
            $path === '/assistant/tokens' => ['token' => 't', 'conversationId' => 'c1', 'expiresAt' => 1],
            default => null,
        };
    }
}
$rk = new RecordingKit('http://127.0.0.1:1', 'k');
$rk->setAssistantConfig(['name' => 'Ada', 'roleTiers' => [], 'tools' => [['name' => 'today', 'description' => 'Today', 'parameters' => ['type' => 'object', 'properties' => []]]]]);
ok($rk->seen[0] === ['PUT', '/assistant/config', '{"name":"Ada","roleTiers":{},"tools":[{"name":"today","description":"Today","parameters":{"type":"object","properties":{}}}]}'], 'assistant: setAssistantConfig keeps empty maps as objects');
ok($rk->getAssistantConfig() === ['name' => 'Ada'] && $rk->seen[1][0] === 'GET', 'assistant: getAssistantConfig');
$tok = $rk->createAssistantToken('u_7', ['name' => 'Ada', 'role' => 'teacher', 'tier' => null, 'metadata' => ['school' => 's1'], 'ttlSeconds' => 600]);
ok($tok['conversationId'] === 'c1' && $rk->seen[2] === ['POST', '/assistant/tokens', '{"userId":"u_7","name":"Ada","role":"teacher","metadata":{"school":"s1"},"ttlSeconds":600}'], 'assistant: createAssistantToken');
$rk->deleteAssistantConversation('u_7', 'c1:x');
ok($rk->seen[3] === ['DELETE', '/assistant/conversations/u_7/c1%3Ax', null], 'assistant: deleteAssistantConversation');
try {
    $rk->deleteAssistantConversation('u_7', '..');
    ok(false, 'assistant: dot segment refused');
} catch (SfuKitError $e) {
    ok($e->status === 400, 'assistant: dot segment refused');
}

echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
