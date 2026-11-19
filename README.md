# appsocom/appsocom (PHP)

PHP server SDK for [Appsocom Live](https://live.appsocom.com): mint join tokens, manage rooms and participants, moderate and verify webhooks. PHP 8.1+ with `ext-curl`, no other dependencies.

```bash
composer require appsocom/appsocom
```

```php
use SfuKit\SfuKit;
use SfuKit\SfuKitError;
use SfuKit\Webhooks;

$kit = new SfuKit('https://api.appsocom.dev', getenv('APPSOCOM_API_KEY')); // project API key from the console

$tok = $kit->createToken('class-1', 'u_7', ['name' => 'Ada', 'role' => 'student', 'metadata' => ['seat' => 3]]);
$tok['token'];

$kit->setRole('class-1', 'u_9', 'student');                 // promote an observer
$kit->mute('class-1', '*', ['mic'], lock: true);            // mute everyone, keep them muted
$kit->setRoomMetadata('class-1', ['topic' => 'Fractions']);
$kit->setTrackPermissions('class-1', 'u_7', ['allowAll' => false, 'allow' => [['userId' => 'u_1']]]);
$kit->removeParticipant('class-1', 'u_8', ban: true);
```

Errors throw `SfuKitError` with `->status` and `->body`.

Webhooks (plain PHP or Laravel):

```php
$event = Webhooks::verify(getenv('APPSOCOM_WEBHOOK_SECRET'), file_get_contents('php://input'), $_SERVER['HTTP_X_SFUKIT_SIGNATURE'] ?? null);
if ($event === null) { http_response_code(401); exit; }
// $event['event'], $event['roomId'] …
http_response_code(204);
```

## AI assistant

Tell the assistant about your app once, mint a token for each signed-in person, and answer its tool calls at your `toolsUrl`:

```php
use SfuKit\AssistantToolError;
use SfuKit\AssistantTools;

$kit->setAssistantConfig([
    'name' => 'Ada',
    'toolsUrl' => 'https://app.example/assistant-tools',
    'tools' => [['name' => 'list_students', 'description' => 'Students in a class', 'parameters' => ['type' => 'object', 'properties' => ['cls' => ['type' => 'string']]]]],
    'appMap' => [['label' => 'Fees', 'path' => '/fees', 'aliases' => ['bursary']]],
]);
$tok = $kit->createAssistantToken($user->id, ['name' => $user->name, 'role' => $user->role, 'metadata' => ['school' => $user->schoolId]]);
// give $tok['token'] to the browser (@appsocom/react <AssistantPanel>)

// assistant-tools.php
$tools = new AssistantTools(getenv('APPSOCOM_WEBHOOK_SECRET'), [
    'list_students' => function (array $args, array $call) use ($db) { // runs as the person who asked
        if ($call['role'] !== 'teacher') {
            throw new AssistantToolError('Only teachers can list students.'); // the model sees this message
        }
        return $db->students($call['metadata']['school'], $args['cls']);
    },
]);
[$status, $body] = $tools->handle(file_get_contents('php://input'), $_SERVER['HTTP_X_SFUKIT_SIGNATURE'] ?? null);
http_response_code($status);
header('Content-Type: application/json');
echo $body;
```

`handle()` refuses bad or stale signatures with 401, answers `{"result": ...}` (`"done"` when a tool returns null) or `{"error": ...}`, and hides any exception other than `AssistantToolError` as "The tool failed." (pass `onError:` to see it). Also: `getAssistantConfig`, `deleteAssistantConversation($userId, $conversationId)`, `AssistantTools::verify`.

All methods: `createToken`, `getRoom`, `listParticipants`, `updateRoom`, `setRoomMetadata`, `lockRoom`, `closeRoom`, `updateParticipant`, `setPermissions`, `setRole`, `setParticipantMetadata`, `setTrackPermissions`, `removeParticipant`, `unban`, `mute`, `admit`, `postChat`, `lowerHands`, `getAttendance`, `getAttendanceCsv`, `clearAttendance`, `startBreakouts`, `endBreakouts`, `moveToGroup`, `setAssistantConfig`, `getAssistantConfig`, `createAssistantToken`, `deleteAssistantConversation`, `health`, `request()`. Standalone: `Tokens::sign`, `Webhooks::verify`, `Webhooks::sign`, `AssistantTools`.

Self-hosting your own deployment instead? Point `url` at it and pass its admin key; the optional `third argument (JWT secret)` signs tokens locally without an HTTP call.

Docs: [docs.appsocom.dev](https://docs.appsocom.dev) · License: MIT
