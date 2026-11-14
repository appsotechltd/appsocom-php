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

All methods: `createToken`, `getRoom`, `listParticipants`, `updateRoom`, `setRoomMetadata`, `lockRoom`, `closeRoom`, `updateParticipant`, `setPermissions`, `setRole`, `setParticipantMetadata`, `setTrackPermissions`, `removeParticipant`, `unban`, `mute`, `admit`, `postChat`, `lowerHands`, `getAttendance`, `getAttendanceCsv`, `clearAttendance`, `startBreakouts`, `endBreakouts`, `moveToGroup`, `health`, `request()`. Standalone: `Tokens::sign`, `Webhooks::verify`, `Webhooks::sign`.

Self-hosting your own deployment instead? Point `url` at it and pass its admin key; the optional `third argument (JWT secret)` signs tokens locally without an HTTP call.

Docs: [docs.appsocom.dev](https://docs.appsocom.dev) · License: MIT
