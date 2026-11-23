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

// ---------- AI Tasks, Speech and Videos ----------

/** A kit that answers from a queue instead of the network, and remembers what was sent. */
final class FakeKit extends SfuKit
{
    /** @var list<array{0:string,1:string,2:array,3:?string,4:int}> */
    public array $seen = [];
    /** @var list<array{0:int,1:string}> */
    public array $replies = [];
    /** @var array{0:int,1:list<string>} */
    public array $streamReply = [200, []];

    protected function exchange(string $method, string $url, array $headers, ?string $payload, int $timeoutSeconds): array
    {
        $this->seen[] = [$method, $url, $headers, $payload, $timeoutSeconds];
        [$status, $body] = array_shift($this->replies) ?? [200, '{}'];
        return [$status, $body, ''];
    }

    protected function stream(string $method, string $url, array $headers, ?string $payload, int $timeoutSeconds, callable $onChunk): array
    {
        $this->seen[] = [$method, $url, $headers, $payload, $timeoutSeconds];
        [$status, $chunks] = $this->streamReply;
        foreach ($chunks as $chunk) {
            if ($onChunk($status, $chunk) === false) {
                return [$status, 'Failure writing output to destination'];
            }
        }
        return [$status, ''];
    }

    /** The last request as "METHOD path" with the host cut off. */
    public function last(): string
    {
        $r = $this->seen[count($this->seen) - 1];
        return $r[0] . ' ' . substr($r[1], strlen('https://kit.test'));
    }

    public function lastPayload(): ?string
    {
        return $this->seen[count($this->seen) - 1][3];
    }
}
function throwsStatus(callable $fn, int $status, string $contains = ''): bool
{
    try {
        $fn();
    } catch (SfuKitError $e) {
        return $e->status === $status && str_contains($e->getMessage(), $contains);
    }
    return false;
}

$fk = new FakeKit('https://kit.test', 'k');
$fk->replies[] = [200, '{"task":"mark","tier":"smart","text":"{}","json":{"score":3},"usage":{"credits":0.1}}'];
$r = $fk->aiTask('mark', ['question' => 'q', 'answer' => 'a'], ['user' => 's1', 'tier' => 'smart', 'maxTokens' => 500, 'label' => null]);
ok($r['json']['score'] === 3 && $fk->last() === 'POST /ai/complete', 'ai: aiTask posts to /ai/complete');
ok($fk->lastPayload() === '{"user":"s1","tier":"smart","maxTokens":500,"task":"mark","input":{"question":"q","answer":"a"}}', 'ai: aiTask body (nulls left out)');
ok(in_array('Authorization: Bearer k', $fk->seen[0][2], true) && $fk->seen[0][4] === 300, 'ai: sends the key, waits up to 300 seconds');
$fk->aiTask('summary', [], ['tier' => 'standard']);
ok(str_contains($fk->lastPayload(), '"input":{}'), 'ai: an empty input is an object');
$fk->aiComplete(['prompt' => 'hi', 'system' => 'Be brief', 'json' => true]);
ok($fk->lastPayload() === '{"prompt":"hi","system":"Be brief","json":true,"stream":false}', 'ai: aiComplete body');
$fk->aiComplete(['system' => 'Mark it', 'messages' => [['role' => 'user', 'content' => 'x']], 'json' => ['type' => 'object', 'properties' => []], 'stream' => true]);
ok($fk->lastPayload() === '{"system":"Mark it","messages":[{"role":"user","content":"x"}],"json":{"type":"object","properties":{}},"stream":false}', 'ai: a JSON Schema keeps its empty maps; stream stays false');
$fk->replies[] = [429, '{"error":"AI Tasks has reached this project\'s daily limit"}'];
ok(throwsStatus(fn () => $fk->aiComplete(['prompt' => 'x']), 429, 'daily limit'), 'ai: errors carry the status');

$fk->streamReply = [200, ["event: text\ndata: {\"text\":\"Hel\"}\n", "\nevent: text\ndata: {\"text\":\"lo é\"}\n\nevent: do", "ne\ndata: {\"usage\":{\"credits\":0.2}}\n\n"]];
$text = '';
$usage = $fk->aiStream(['prompt' => 'x', 'json' => true, 'tier' => 'standard'], function (string $piece) use (&$text): void {
    $text .= $piece;
});
ok($text === 'Hello é' && $usage === ['credits' => 0.2], 'ai: aiStream gives the text pieces, then the usage (events split across chunks)');
ok($fk->last() === 'POST /ai/complete' && $fk->lastPayload() === '{"prompt":"x","tier":"standard","stream":true}', 'ai: aiStream body (no json)');
$fk->streamReply = [200, ["event: text\ndata: {\"text\":\"Hel\"}\n\nevent: error\ndata: {\"error\":\"the model stopped\"}\n\n"]];
ok(throwsStatus(fn () => $fk->aiStream(['prompt' => 'x'], fn (string $p) => null), 502, 'the model stopped'), 'ai: a stream error throws');
$fk->streamReply = [429, ['{"error":"AI Tasks has reached ', 'this project\'s daily limit"}']];
ok(throwsStatus(fn () => $fk->aiStream(['prompt' => 'x'], fn (string $p) => null), 429, 'daily limit'), 'ai: a refused stream throws with the status');
$fk->streamReply = [200, ["event: text\ndata: {\"text\":\"a\"}\n\n", "event: text\ndata: {\"text\":\"b\"}\n\n"]];
$got = [];
$stopped = $fk->aiStream(['prompt' => 'x'], function (string $p) use (&$got): bool {
    $got[] = $p;
    return false;
});
ok($got === ['a'] && $stopped === null, 'ai: returning false from the callback stops reading');
try {
    $fk->aiStream(['prompt' => 'x'], function (string $p): void {
        throw new \LogicException('mine');
    });
    ok(false, 'ai: an exception from the callback comes out');
} catch (\LogicException $e) {
    ok($e->getMessage() === 'mine', 'ai: an exception from the callback comes out');
}

$fk = new FakeKit('https://kit.test', 'k');
$fk->setAiSettings(['dailyBudgetUsd' => 2]);
$fk->getAiSettings();
$fk->getAiUsage(30);
$fk->getAiUsage();
$fk->getAiAudit('2026-11-20', 'u1');
$fk->getAiAudit();
ok(array_map(static fn (array $s) => "$s[0] " . substr($s[1], strlen('https://kit.test')), $fk->seen) === ['PUT /ai/settings', 'GET /ai/settings', 'GET /ai/usage?days=30', 'GET /ai/usage?days=7', 'GET /ai/audit?day=2026-11-20&user=u1', 'GET /ai/audit'], 'ai: settings, usage and audit paths');
ok($fk->seen[0][3] === '{"dailyBudgetUsd":2}', 'ai: setAiSettings body');

$fk = new FakeKit('https://kit.test', 'k');
$fk->replies[] = [200, "\x01\x02"];
ok($fk->textToSpeech('Hi', ['language' => 'yo', 'cache' => null, 'response' => 'url']) === "\x01\x02", 'speech: textToSpeech returns the audio bytes');
ok($fk->last() === 'POST /speech/tts' && $fk->lastPayload() === '{"text":"Hi","language":"yo","response":"audio"}', 'speech: textToSpeech body');
$fk->replies[] = [200, '{"url":"https://kit.test/speech/audio/t/x.mp3?exp=1&sig=a","cached":true,"characters":0}'];
$link = $fk->textToSpeechUrl('Hi', ['expiresIn' => 600, 'format' => 'wav']);
ok(str_contains($link['url'], '/speech/audio/') && $fk->lastPayload() === '{"text":"Hi","expiresIn":600,"format":"wav","response":"url"}', 'speech: textToSpeechUrl');
$fk->replies[] = [200, '{"text":"hello","seconds":3,"provider":"deepgram"}'];
$out = $fk->speechToText("\x00\x01\x02", ['contentType' => 'audio/webm', 'language' => 'en']);
$req = $fk->seen[count($fk->seen) - 1];
ok($out['text'] === 'hello' && $req[1] === 'https://kit.test/speech/stt?language=en' && $req[3] === "\x00\x01\x02" && $req[4] === 120, 'speech: speechToText sends the recording as the body');
ok(in_array('Content-Type: audio/webm', $req[2], true) && in_array('Authorization: Bearer k', $req[2], true), 'speech: speechToText content type and key');
$fk->speechToText('a');
ok(in_array('Content-Type: application/octet-stream', $fk->seen[count($fk->seen) - 1][2], true) && $fk->last() === 'POST /speech/stt', 'speech: default content type, no query');
$fk->replies[] = [429, '{"error":"the project\'s daily speech budget is used up"}'];
ok(throwsStatus(fn () => $fk->speechToText('quota'), 429, 'daily speech budget'), 'speech: errors carry the status');
$fk->getSpeechUsage();
ok($fk->last() === 'GET /speech/usage', 'speech: getSpeechUsage');

$fk->replies[] = [200, '{"languages":[{"code":"fr","name":"French"}]}'];
ok($fk->getLanguages()[0]['code'] === 'fr' && $fk->last() === 'GET /languages', 'translate: getLanguages');
$fk->replies[] = [200, '{"from":"en","to":["fr"],"translations":{"fr":"Bonjour"},"text":"Bonjour"}'];
$t = $fk->translateText('Hello', ['fr', 'de'], ['from' => 'en', 'tier' => 'smart', 'glossary' => ['Appsocom' => 'Appsocom'], 'label' => null]);
ok($t['text'] === 'Bonjour' && $fk->last() === 'POST /translate/text', 'translate: translateText');
ok($fk->lastPayload() === '{"text":"Hello","to":["fr","de"],"from":"en","tier":"smart","glossary":{"Appsocom":"Appsocom"}}', 'translate: translateText body (nulls left out)');
$fk->replies[] = [200, '{"from":"en","transcript":"hello","translations":{"fr":"bonjour"}}'];
$h = $fk->translateSpeech("\x00\x01", ['fr', 'de'], ['contentType' => 'audio/webm', 'speak' => true, 'voice' => 'v1']);
$req = $fk->seen[count($fk->seen) - 1];
ok($h['transcript'] === 'hello' && $req[1] === 'https://kit.test/translate/speech?to=fr%2Cde&voice=v1&speak=true' && $req[3] === "\x00\x01" && $req[4] === 180, 'translate: translateSpeech sends the recording with its options in the query');
ok(in_array('Content-Type: audio/webm', $req[2], true), 'translate: translateSpeech content type');
$fk->replies[] = [200, '{"roomId":"room-1","agentId":"a1"}'];
ok($fk->startInterpreter('room-1', 'fr', ['voiceName' => 'v2'])['agentId'] === 'a1' && $fk->last() === 'POST /rooms/room-1/interpreters' && $fk->lastPayload() === '{"language":"fr","voiceName":"v2"}', 'interpreters: startInterpreter');
$fk->replies[] = [200, '{"interpreters":[{"agentId":"a1","language":"fr","listeners":2}],"max":3}'];
ok($fk->listInterpreters('room-1')[0]['listeners'] === 2 && $fk->last() === 'GET /rooms/room-1/interpreters', 'interpreters: listInterpreters');
$fk->replies[] = [200, '{"stopped":true}'];
$fk->stopInterpreter('room-1', 'a1');
ok($fk->last() === 'DELETE /rooms/room-1/interpreters/a1', 'interpreters: stopInterpreter');
$fk->setSpeechDailyBudget(null);
ok($fk->last() === 'PUT /speech/config' && $fk->lastPayload() === '{"dailyBudgetUsd":null}', 'speech: setSpeechDailyBudget(null)');
$fk->setSpeechDailyBudget(2);
ok($fk->lastPayload() === '{"dailyBudgetUsd":2.0}', 'speech: setSpeechDailyBudget(2)');

$v = str_repeat('a', 32);
$fk = new FakeKit('https://kit.test', 'k');
$calls = [];
$note = function () use ($fk, &$calls): void {
    $calls[] = $fk->last() . ' ' . ($fk->lastPayload() ?? '-');
};
$fk->createVideoUpload(['maxDurationSeconds' => 600, 'name' => 'Lesson 1', 'uploadLength' => 5000, 'meta' => []]);
$note();
$fk->copyVideo('https://files.example/a.mp4', ['name' => 'Old']);
$note();
$fk->listVideos(['after' => 'abc', 'limit' => 10]);
$note();
$fk->listVideos();
$note();
$fk->getVideo($v);
$note();
$fk->updateVideo($v, ['name' => 'New']);
$note();
$fk->videoPlayback($v, ['expiresInSeconds' => 900]);
$note();
$fk->videoPlayback($v);
$note();
$fk->getVideoUsage();
$note();
$fk->deleteVideo($v);
$note();
ok($calls === [
    'POST /videos/uploads {"maxDurationSeconds":600,"name":"Lesson 1","uploadLength":5000,"meta":{}}',
    'POST /videos/copy {"url":"https://files.example/a.mp4","name":"Old"}',
    'GET /videos?after=abc&limit=10 -',
    'GET /videos -',
    "GET /videos/$v -",
    "PATCH /videos/$v {\"name\":\"New\"}",
    "POST /videos/$v/playback {\"expiresInSeconds\":900}",
    "POST /videos/$v/playback {}",
    'GET /videos/usage -',
    "DELETE /videos/$v -",
], 'videos: the methods call the right routes');
ok(throwsStatus(fn () => $fk->createVideoUpload(['name' => 'x']), 400, 'maxDurationSeconds'), 'videos: maxDurationSeconds is required');
foreach (['..', 'a/b', str_repeat('x', 200)] as $bad) {
    ok(throwsStatus(fn () => $fk->getVideo($bad), 400), 'videos: bad id refused');
}

$link = 'https://kit.test/videos/upload/p/v?t=x';
$fk = new FakeKit('https://kit.test', 'k');
$fk->replies[] = [200, '{"videoId":"v","status":"processing"}'];
$one = $fk->uploadVideoFile(['videoId' => 'v', 'uploadUrl' => $link, 'protocol' => 'put'], '0123456789');
$req = $fk->seen[0];
ok($one['status'] === 'processing' && count($fk->seen) === 1 && $req[0] === 'PUT' && $req[1] === $link && $req[3] === '0123456789', 'upload: one PUT');
ok(!array_filter($req[2], static fn (string $h) => stripos($h, 'authorization') === 0), 'upload: the link has its own token; the key stays home');
foreach (['0123456789', 'stream'] as $kind) {
    $fk = new FakeKit('https://kit.test', 'k');
    $fk->replies = [[200, '{"partNumber":1,"etag":"e1"}'], [200, '{"partNumber":2,"etag":"e2"}'], [200, '{"partNumber":3,"etag":"e3"}'], [200, '{"videoId":"v","status":"processing"}']];
    $source = '0123456789';
    if ($kind === 'stream') {
        $source = fopen('php://memory', 'r+');
        fwrite($source, '0123456789');
        rewind($source);
    }
    $fk->uploadVideoFile(['videoId' => 'v', 'uploadUrl' => $link, 'protocol' => 'parts', 'partSize' => 4, 'parts' => 3], $source);
    ok(array_map(static fn (array $s) => "$s[0] " . substr($s[1], strlen($link)) . ' ' . strlen((string) $s[3]), array_slice($fk->seen, 0, 3)) === ['PUT &part=1 4', 'PUT &part=2 4', 'PUT &part=3 2'], "upload: parts of 4 bytes ($kind)");
    $done = $fk->seen[3];
    ok($done[0] === 'POST' && $done[1] === "$link&complete" && $done[3] === '{"parts":[{"partNumber":1,"etag":"e1"},{"partNumber":2,"etag":"e2"},{"partNumber":3,"etag":"e3"}]}' && in_array('Content-Type: application/json', $done[2], true), "upload: finishing POST ($kind)");
    ok(count($fk->seen) === 4 && !array_filter(array_merge(...array_column($fk->seen, 2)), static fn (string $h) => stripos($h, 'authorization') === 0), "upload: no key sent to the link ($kind)");
}
ok(throwsStatus(fn () => $fk->uploadVideoFile(['uploadUrl' => 'file:///etc/passwd', 'protocol' => 'put'], 'x'), 400, 'invalid upload link'), 'upload: links must be http(s)');
ok(throwsStatus(fn () => $fk->uploadVideoFile(['uploadUrl' => $link, 'protocol' => 'put'], 42), 400), 'upload: file must be bytes or a stream');

echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
