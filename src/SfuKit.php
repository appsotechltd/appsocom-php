<?php

declare(strict_types=1);

namespace SfuKit;

/**
 * Admin client for one sfukit deployment.
 *
 *   $kit = new SfuKit\SfuKit(getenv('SFUKIT_URL'), getenv('SFUKIT_ADMIN_API_KEY'));
 *   $tok = $kit->createToken('class-1', 'u_7', ['name' => 'Ada', 'role' => 'student']);
 *   $tok['token'];
 */
class SfuKit
{
    public const VERSION = '0.8.0';
    public readonly string $url;

    /**
     * @param string $url       where the kit's Worker routes live, e.g. "https://video.client.com"
     * @param string $adminKey  the deployment's ADMIN_API_KEY (keep it on the server)
     * @param ?string $jwtSecret the deployment's JWT_SECRET; when set, createToken() signs locally
     */
    public function __construct(
        string $url,
        private readonly string $adminKey,
        private readonly ?string $jwtSecret = null,
        private readonly int $timeoutSeconds = 10,
    ) {
        if ($url === '' || $adminKey === '') {
            throw new \InvalidArgumentException('url and adminKey are required');
        }
        $this->url = rtrim($url, '/');
    }

    // ---------- tokens ----------

    /**
     * Mint a join token. Options: name, role, permissions, canPublish, metadata, maxParticipants, ttlSeconds,
     * dataSaver ("off", "low", "minimal", "audio": the preset the client starts with).
     * @return array{token:string, expiresAt:int}
     */
    public function createToken(string $roomId, string $userId, array $options = []): array
    {
        if ($this->jwtSecret !== null) {
            return Tokens::sign($this->jwtSecret, $roomId, $userId, $options);
        }
        if (isset($options['metadata'])) {
            $options['metadata'] = (object) $options['metadata'];
        }
        return $this->request('POST', '/tokens', ['roomId' => $roomId, 'userId' => $userId] + $options);
    }

    // ---------- rooms ----------

    /** ['roomId' => …, 'room' => settings, 'participants' => […]] */
    public function getRoom(string $roomId): array
    {
        return $this->request('GET', '/rooms/' . self::id($roomId));
    }

    public function listParticipants(string $roomId): array
    {
        return $this->getRoom($roomId)['participants'];
    }

    /** Keys: locked, chat, waitingRoom, spotlight (userIds), metadata (array, or null to clear). Returns the settings. */
    public function updateRoom(string $roomId, array $update): array
    {
        return $this->request('PATCH', '/rooms/' . self::id($roomId), self::objects($update))['room'];
    }

    public function setRoomMetadata(string $roomId, ?array $metadata): array
    {
        return $this->updateRoom($roomId, ['metadata' => $metadata]);
    }

    public function lockRoom(string $roomId, bool $locked = true): array
    {
        return $this->updateRoom($roomId, ['locked' => $locked]);
    }

    /** Disconnect everyone; returns how many connections were closed. */
    public function closeRoom(string $roomId): int
    {
        return $this->request('DELETE', '/rooms/' . self::id($roomId))['disconnected'];
    }

    // ---------- participants ----------

    /** Keys: role, permissions, name, metadata, trackPermissions (null clears the last three). Returns connections updated. */
    public function updateParticipant(string $roomId, string $userId, array $update): int
    {
        $path = '/rooms/' . self::id($roomId) . '/participants/' . self::id($userId);
        return $this->request('PATCH', $path, self::objects($update))['updated'];
    }

    public function setPermissions(string $roomId, string $userId, ?array $permissions): int
    {
        return $this->updateParticipant($roomId, $userId, ['permissions' => $permissions]);
    }

    /** e.g. promote an observer: setRole($room, $user, 'student'). */
    public function setRole(string $roomId, string $userId, string $role, ?array $permissions = null): int
    {
        return $this->updateParticipant($roomId, $userId, ['role' => $role] + ($permissions !== null ? ['permissions' => $permissions] : []));
    }

    public function setParticipantMetadata(string $roomId, string $userId, ?array $metadata): int
    {
        return $this->updateParticipant($roomId, $userId, ['metadata' => $metadata]);
    }

    /** ['allowAll' => false, 'allow' => [['userId' => 'u_1', 'kinds' => ['video']]]]; null = everyone may receive. */
    public function setTrackPermissions(string $roomId, string $userId, ?array $trackPermissions): int
    {
        return $this->updateParticipant($roomId, $userId, ['trackPermissions' => $trackPermissions]);
    }

    /** Disconnect a user (all their tabs). $ban keeps them out until the room empties. */
    public function removeParticipant(string $roomId, string $userId, bool $ban = false): int
    {
        $path = '/rooms/' . self::id($roomId) . '/participants/' . self::id($userId) . ($ban ? '?ban=true' : '');
        return $this->request('DELETE', $path)['disconnected'];
    }

    public function unban(string $roomId, string $userId): bool
    {
        return $this->request('DELETE', '/rooms/' . self::id($roomId) . '/bans/' . self::id($userId))['removed'];
    }

    /** Turn off devices of one user, or of everyone without `moderate` ("*"). $lock also takes the permission away. */
    public function mute(string $roomId, string $userId, array $devices = ['mic'], bool $lock = false): int
    {
        return $this->request('POST', '/rooms/' . self::id($roomId) . '/mute', ['userId' => $userId, 'devices' => array_values($devices), 'lock' => $lock])['muted'];
    }

    // ---------- classroom features ----------

    public function admit(string $roomId, string $userId): int
    {
        return $this->request('POST', '/rooms/' . self::id($roomId) . '/admit', ['userId' => $userId])['admitted'];
    }

    public function postChat(string $roomId, string $text, ?string $to = null): mixed
    {
        return $this->request('POST', '/rooms/' . self::id($roomId) . '/chat', ['text' => $text] + ($to !== null ? ['to' => $to] : []))['message'];
    }

    public function lowerHands(string $roomId, ?string $userId = null): int
    {
        if ($userId !== null) {
            return $this->request('DELETE', '/rooms/' . self::id($roomId) . '/hands/' . self::id($userId))['updated'];
        }
        return $this->request('DELETE', '/rooms/' . self::id($roomId) . '/hands')['lowered'];
    }

    public function getAttendance(string $roomId): array
    {
        return $this->request('GET', '/rooms/' . self::id($roomId) . '/attendance');
    }

    public function getAttendanceCsv(string $roomId): string
    {
        return $this->request('GET', '/rooms/' . self::id($roomId) . '/attendance?format=csv', null, true);
    }

    public function clearAttendance(string $roomId): void
    {
        $this->request('DELETE', '/rooms/' . self::id($roomId) . '/attendance');
    }

    /** $groups: [['name' => 'Group 1', 'userIds' => […]], …] */
    public function startBreakouts(string $roomId, array $groups): array
    {
        return $this->request('PUT', '/rooms/' . self::id($roomId) . '/breakouts', ['groups' => $groups])['groups'];
    }

    public function endBreakouts(string $roomId): void
    {
        $this->request('DELETE', '/rooms/' . self::id($roomId) . '/breakouts');
    }

    /** Move a user to a breakout group (null = main room). */
    public function moveToGroup(string $roomId, string $userId, ?string $group): int
    {
        return $this->request('POST', '/rooms/' . self::id($roomId) . '/move', ['userId' => $userId, 'group' => $group])['moved'];
    }

    // ---------- recording (needs the kit's STORAGE bucket; video needs the recorder bot) ----------

    /** Options: video (bool, uses the recorder bot), composite (bool, default true), maxMinutes (int). */
    public function startRecording(string $roomId, array $options = []): array
    {
        return $this->request('POST', '/rooms/' . self::id($roomId) . '/recording', (object) $options)['recording'];
    }

    /** The recording in progress, or null. */
    public function recordingStatus(string $roomId): ?array
    {
        return $this->request('GET', '/rooms/' . self::id($roomId) . '/recording')['recording'];
    }

    /** Stop recording; returns the manifest. SfuKitError 404 if nothing is recording. */
    public function stopRecording(string $roomId): array
    {
        return $this->request('DELETE', '/rooms/' . self::id($roomId) . '/recording');
    }

    public function listRecordings(string $roomId): array
    {
        return $this->request('GET', '/rooms/' . self::id($roomId) . '/recordings')['recordings'];
    }

    /** The file's bytes (WAV or WebM). */
    public function downloadRecording(string $roomId, string $recordingId, string $file): string
    {
        // The kit's own patterns for the parts of a recording download path.
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D', $recordingId)) {
            throw new SfuKitError(400, "invalid recording id \"$recordingId\" (letters, numbers, -, max 64)");
        }
        if (!preg_match('/^[A-Za-z0-9._@-]{1,128}$/D', $file) || $file === '.' || $file === '..') {
            throw new SfuKitError(400, "invalid recording file \"$file\" (letters, numbers, . _ @ -, max 128, not . or ..)");
        }
        return $this->request('GET', '/rooms/' . self::id($roomId) . '/recordings/' . rawurlencode($recordingId) . '/' . rawurlencode($file), null, true);
    }

    // ---------- AI lessons (an AI tutor, or an AI-taught class) ----------

    /**
     * Start an AI lesson. $lesson: ['title' => …, 'subject' => …, 'level' => …, 'curriculum' => …, 'sections' => [...] or 'text' => …].
     * $options: learnerId, floor, scope ('strict'|'guided'|'open'), maxMinutes, llmModel, voice, avatar…
     */
    public function startLesson(string $roomId, ?array $lesson, string $mode = 'class', array $options = []): array
    {
        // $lesson = null with $options['planId']: teach an approved plan from the lesson library.
        $body = array_merge($options, ['mode' => $mode]);
        if ($lesson !== null) {
            $body['lesson'] = $lesson;
        }
        return $this->request('POST', '/rooms/' . self::id($roomId) . '/lessons', $body);
    }

    public function getLesson(string $roomId, string $lessonId): array
    {
        return $this->request('GET', '/rooms/' . self::id($roomId) . '/lessons/' . self::id($lessonId));
    }

    /** $control: ['action' => 'pause'|'resume'|'end'|'goto'|'call-on'|'floor-policy'|'scope'|'note', …]. */
    public function controlLesson(string $roomId, string $lessonId, array $control): void
    {
        $this->request('POST', '/rooms/' . self::id($roomId) . '/lessons/' . self::id($lessonId) . '/control', $control);
    }

    public function stopLesson(string $roomId, string $lessonId): void
    {
        $this->request('DELETE', '/rooms/' . self::id($roomId) . '/lessons/' . self::id($lessonId));
    }

    /** A finished lesson's report: summary, per-learner results and feedback, alerts, transcript. */
    public function lessonReport(string $lessonId): array
    {
        return $this->request('GET', '/lessons/' . self::id($lessonId) . '/report');
    }

    // ---------- AI Presenter (product demos and walkthroughs) ----------

    /**
     * Start an AI Presenter from an approved presentation kit.
     * $options: planId (or lesson), lead ('ai'|'human'), name, voice, avatar, reportEmails, webhookUrl, maxMinutes.
     * Returns ['presentationId', 'lessonId', 'agentId', 'roomId', …]. Stop it with stopLesson().
     */
    public function startPresenter(string $roomId, array $options): array
    {
        return $this->request('POST', '/rooms/' . self::id($roomId) . '/presenter', $options);
    }

    /** Change who leads: 'ai' or 'human'. */
    public function setPresenterLead(string $roomId, string $presentationId, string $lead): void
    {
        $this->controlLesson($roomId, $presentationId, ['action' => 'lead', 'lead' => $lead]);
    }

    /**
     * The live demo's cloud browser: 'take', 'give', 'close', or an op such as
     * ['op' => 'click-at', 'x' => 0.4, 'y' => 0.2], ['op' => 'scroll', 'direction' => 'down'].
     */
    public function presenterBrowser(string $roomId, string $presentationId, string|array $action): void
    {
        $body = is_array($action) ? ['action' => 'browser', 'browser' => 'op', 'op' => $action] : ['action' => 'browser', 'browser' => $action];
        $this->controlLesson($roomId, $presentationId, $body);
    }

    /** Presentations that ended, newest first (optionally of one kit). */
    public function listPresentationReports(?string $planId = null): array
    {
        return $this->request('GET', '/presentation-reports' . ($planId !== null ? '?planId=' . rawurlencode($planId) : ''));
    }

    /** One presentation: summary, recommendations, questions and suggestions. */
    public function getPresentationReport(string $reportId): array
    {
        return $this->request('GET', '/presentation-reports/' . self::id($reportId));
    }

    /** Mark a suggestion (its index in the report) 'new', 'reviewed', 'accepted' or 'dismissed'. */
    public function reviewSuggestion(string $reportId, int $index, string $status): array
    {
        return $this->request('PUT', '/presentation-reports/' . self::id($reportId) . '/suggestions/' . $index, ['status' => $status]);
    }

    // ---- self-running presentations: a link the AI Presenter presents on its own, privately to each visitor

    /** A link from an approved kit: ['planId' => …, 'title', 'reportEmails', 'askDetails', 'maxMinutes', 'perDay', 'maxConcurrent', …]. */
    public function createPresentationLink(array $options): array
    {
        return $this->request('POST', '/presentation-links', $options);
    }

    public function listPresentationLinks(): array
    {
        return $this->request('GET', '/presentation-links');
    }

    public function getPresentationLink(string $linkId): array
    {
        return $this->request('GET', '/presentation-links/' . self::id($linkId));
    }

    /** Fields you leave out stay; ['active' => false] closes the link. */
    public function updatePresentationLink(string $linkId, array $patch): array
    {
        return $this->request('PATCH', '/presentation-links/' . self::id($linkId), $patch);
    }

    public function deletePresentationLink(string $linkId): void
    {
        $this->request('DELETE', '/presentation-links/' . self::id($linkId));
    }

    /**
     * A visitor opened the link: ['name' => …, 'email' => …, 'company' => …, 'phone' => …].
     * Returns ['sessionId', 'roomId', 'token', 'expiresAt']; the presenter starts when they join.
     */
    public function startPresentationSession(string $linkId, array $visitor): array
    {
        return $this->request('POST', '/presentation-links/' . self::id($linkId) . '/sessions', ['visitor' => $visitor]);
    }

    /** Visitors and their leads, newest first (optionally of one link). */
    public function listPresentationSessions(?string $linkId = null): array
    {
        return $this->request('GET', '/presentation-sessions' . ($linkId !== null ? '?linkId=' . rawurlencode($linkId) : ''));
    }

    public function getPresentationSession(string $sessionId): array
    {
        return $this->request('GET', '/presentation-sessions/' . self::id($sessionId));
    }

    /** A host token for a colleague to step into a visitor's presentation. */
    public function joinPresentationSession(string $sessionId, string $name, ?string $userId = null): array
    {
        return $this->request('POST', '/presentation-sessions/' . self::id($sessionId) . '/join', array_filter(['name' => $name, 'userId' => $userId], fn ($v) => $v !== null));
    }

    public function learnerProgress(string $learnerId): array
    {
        return $this->request('GET', '/learners/' . rawurlencode($learnerId) . '/progress');
    }

    public function deleteLearnerProgress(string $learnerId): void
    {
        $this->request('DELETE', '/learners/' . rawurlencode($learnerId) . '/progress');
    }

    // ---------- lesson library: drafts, review, courses, timetables ----------

    /** ['requireReview' => bool, 'defaults' => [...]]. With requireReview on (the default) only approved plans are taught. */
    public function lessonSettings(): array
    {
        return $this->request('GET', '/lesson-settings');
    }

    public function setLessonSettings(array $settings): array
    {
        return $this->request('PUT', '/lesson-settings', $settings);
    }

    /** Plans in the library; $status: 'draft', 'approved' or 'archived'. */
    public function listLessonPlans(?string $status = null): array
    {
        return $this->request('GET', '/lesson-plans' . ($status ? '?status=' . rawurlencode($status) : ''))['plans'];
    }

    /**
     * A written 'plan' (saved as a draft), or material for the AI to draft from: 'text', 'link'
     * (Google Docs/Slides), 'topic', 'curriculum', 'exam'. AI drafts come back with draftState
     * 'processing'; the lesson_plan_ready webhook says when they're ready to review.
     */
    public function createLessonPlan(array $input): array
    {
        return $this->request('POST', '/lesson-plans', $input);
    }

    public function getLessonPlan(string $planId): array
    {
        return $this->request('GET', '/lesson-plans/' . self::id($planId));
    }

    /** Edit a plan. An approved plan keeps teaching its approved version until you approve again. */
    public function updateLessonPlan(string $planId, array $changes): array
    {
        return $this->request('PUT', '/lesson-plans/' . self::id($planId), $changes);
    }

    /** Ask the AI to change a plan, e.g. "make section 2 simpler". */
    public function reviseLessonPlan(string $planId, string $instruction): array
    {
        return $this->request('POST', '/lesson-plans/' . self::id($planId) . '/revise', ['instruction' => $instruction]);
    }

    /** Approve the plan as it is now (a new version). Start lessons with ['planId' => …] to teach it. */
    public function approveLessonPlan(string $planId): array
    {
        return $this->request('POST', '/lesson-plans/' . self::id($planId) . '/approve');
    }

    public function lessonPlanVersions(string $planId): array
    {
        return $this->request('GET', '/lesson-plans/' . self::id($planId) . '/versions')['versions'];
    }

    /** A 5-minute tutor run of the plan as it is now (even unapproved), with no reports or emails. */
    public function trialLessonPlan(string $planId, ?string $roomId = null): array
    {
        return $this->request('POST', '/lesson-plans/' . self::id($planId) . '/trial', $roomId ? ['roomId' => $roomId] : new \stdClass());
    }

    public function archiveLessonPlan(string $planId): void
    {
        $this->request('DELETE', '/lesson-plans/' . self::id($planId));
    }

    public function listCourses(): array
    {
        return $this->request('GET', '/courses')['courses'];
    }

    /** Create a course ['title', 'subject'?, 'level'?, 'plans' => planIds in order], or update one that has an 'id'. */
    public function saveCourse(array $course): array
    {
        $id = $course['id'] ?? null;
        unset($course['id']);
        return $id ? $this->request('PUT', '/courses/' . self::id($id), $course) : $this->request('POST', '/courses', $course);
    }

    public function deleteCourse(string $courseId): void
    {
        $this->request('DELETE', '/courses/' . self::id($courseId));
    }

    public function listTimetables(): array
    {
        return $this->request('GET', '/timetables')['timetables'];
    }

    /** Create a timetable, or update one that has an 'id'. Two lessons in one room at once → 409 unless $allowClashes. */
    public function saveTimetable(array $timetable, bool $allowClashes = false): array
    {
        $id = $timetable['id'] ?? null;
        unset($timetable['id']);
        $q = $allowClashes ? '?allowClashes=true' : '';
        return $id ? $this->request('PUT', '/timetables/' . self::id($id) . $q, $timetable) : $this->request('POST', '/timetables' . $q, $timetable);
    }

    public function deleteTimetable(string $timetableId): void
    {
        $this->request('DELETE', '/timetables/' . self::id($timetableId));
    }

    /** Scheduled lessons in the next $days days, with their status. */
    public function timetableRuns(int $days = 7, ?string $timetableId = null): array
    {
        $q = ['days' => $days] + ($timetableId ? ['timetableId' => $timetableId] : []);
        return $this->request('GET', '/timetable-runs?' . http_build_query($q))['runs'];
    }

    public function startTimetableRun(string $key): array
    {
        return $this->request('POST', '/timetable-runs/' . rawurlencode($key) . '/start');
    }

    public function skipTimetableRun(string $key): array
    {
        return $this->request('POST', '/timetable-runs/' . rawurlencode($key) . '/skip');
    }

    // ---------- AI assistant ----------

    /**
     * Tell the assistant about your app; replaces the whole config. Keys: name, instructions,
     * tools ([name, description, parameters, risk, pinned, assistantAllowed]), toolsUrl,
     * appMap ([label, path, description, aliases]), defaultTier, roleTiers, knowledge, retentionDays.
     */
    public function setAssistantConfig(array $config): array
    {
        if (isset($config['roleTiers']) && is_array($config['roleTiers'])) {
            $config['roleTiers'] = (object) $config['roleTiers'];
        }
        if (isset($config['tools']) && is_array($config['tools'])) {
            foreach ($config['tools'] as $i => $tool) {
                if (is_array($tool) && isset($tool['parameters']) && is_array($tool['parameters'])) {
                    $config['tools'][$i]['parameters'] = self::schemaObjects($tool['parameters']);
                }
            }
        }
        return $this->request('PUT', '/assistant/config', (object) $config)['config'];
    }

    public function getAssistantConfig(): array
    {
        return $this->request('GET', '/assistant/config')['config'];
    }

    /**
     * A token for one signed-in person to chat with the assistant. Options: name, role, conversationId,
     * tools (names), paths, tier, retentionDays, monthlyMessages (this person's messages per month),
     * metadata (string values, passed back with every tool call), context (what your app knows about
     * this person that the assistant should always have, at most 3,000 characters), reportUsage (add tier,
     * units, cost and tokens to the done event, for a backend that relays answers), knowledge (a knowledge
     * base collection to answer from, for an app that keeps each customer's knowledge apart),
     * ttlSeconds (default 3600, at most 43200).
     * @return array{token:string, conversationId:string, expiresAt:int}
     */
    public function createAssistantToken(string $userId, array $options = []): array
    {
        if (isset($options['metadata'])) {
            $options['metadata'] = (object) $options['metadata'];
        }
        return $this->request('POST', '/assistant/tokens', ['userId' => $userId] + array_filter($options, static fn ($v) => $v !== null));
    }

    /** Forget one person's conversation (its memory and transcript). */
    public function deleteAssistantConversation(string $userId, string $conversationId): void
    {
        $this->request('DELETE', '/assistant/conversations/' . self::id($userId) . '/' . self::id($conversationId));
    }

    // ---------- AI Tasks (/ai/*; called AI Text in code) ----------

    /** Seconds AI Tasks calls may take (they write whole answers); longer than the client's timeout. */
    public const AI_TIMEOUT_SECONDS = 300;

    /**
     * One Claude call with your own prompt: `prompt`, or `system` and `messages`. Keys: prompt, system,
     * messages ([['role' => 'user'|'assistant', 'content' => …]]), json (true for any JSON object, or a
     * JSON Schema array for an answer that keeps to it), tier ('standard', 'smart', 'best'), maxTokens
     * (default 4000, at most 32000), user (your id for the person this is for: per-person monthly
     * allowance, audit), label (your own label, shown in usage and audit).
     * @return array{task:string, tier:string, text:string, json?:mixed, error?:string, stopReason:?string, usage:array}
     */
    public function aiComplete(array $request): array
    {
        return $this->call('POST', '/ai/complete', Json::encode(array_merge(self::aiBody($request), ['stream' => false])), 'application/json', max($this->timeoutSeconds, self::AI_TIMEOUT_SECONDS));
    }

    /**
     * A ready-made task: 'write', 'mark', 'questions', 'summary', 'moderate' or 'extract' (see the AI Tasks
     * docs for each input). Options: tier, maxTokens, user, label.
     */
    public function aiTask(string $task, array $input, array $options = []): array
    {
        return $this->call('POST', '/ai/complete', Json::encode(array_merge(self::aiBody($options), ['task' => $task, 'input' => (object) $input])), 'application/json', max($this->timeoutSeconds, self::AI_TIMEOUT_SECONDS));
    }

    /**
     * Your own prompt, streamed: `$onText` is called with each piece of text as it is written. Takes the keys
     * of aiComplete except json. Returns the call's usage (model, tokens, cost, credits), or null if the
     * stream ended without it. Throws SfuKitError when the kit refuses the call (429 at the daily limit) or
     * the stream fails. Return false from `$onText` to stop reading.
     * @param callable(string): (void|bool) $onText
     */
    public function aiStream(array $request, callable $onText): ?array
    {
        unset($request['json']);
        $headers = $this->headers('application/json');
        $buffer = '';
        $errorBody = '';
        $usage = null;
        $failure = null;
        $finished = false;
        [$status, $err] = $this->stream(
            'POST',
            $this->url . '/ai/complete',
            $headers,
            Json::encode(array_merge(self::aiBody($request), ['stream' => true])),
            max($this->timeoutSeconds, self::AI_TIMEOUT_SECONDS),
            function (int $status, string $chunk) use (&$buffer, &$errorBody, &$usage, &$failure, &$finished, $onText): bool {
                if ($status < 200 || $status >= 300) {
                    $errorBody .= $chunk;
                    return true;
                }
                if ($finished) {
                    return true;
                }
                $buffer .= $chunk;
                try {
                    while (($end = strpos($buffer, "\n\n")) !== false) {
                        $block = substr($buffer, 0, $end);
                        $buffer = substr($buffer, $end + 2);
                        $event = preg_match('/^event: (.*)$/m', $block, $m) ? rtrim($m[1], "\r") : null;
                        $data = preg_match('/^data: (.*)$/m', $block, $m) ? rtrim($m[1], "\r") : null;
                        if ($event === null || $data === null) {
                            continue;
                        }
                        $d = Json::decode($data);
                        if ($event === 'text' && !empty($d['text'])) {
                            if ($onText($d['text']) === false) {
                                $finished = true;
                                return false;
                            }
                        } elseif ($event === 'error') {
                            $failure = new SfuKitError(502, 'sfukit POST /ai/complete: ' . ($d['error'] ?? 'stream failed'), $d);
                            return false;
                        } elseif ($event === 'done') {
                            $usage = $d['usage'] ?? null;
                            $finished = true;
                            return true;
                        }
                    }
                } catch (\Throwable $e) {
                    $failure = $e;
                    return false;
                }
                return true;
            }
        );
        if ($failure !== null) {
            throw $failure;
        }
        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            throw self::httpError('POST', '/ai/complete', $status, $errorBody);
        }
        if ($finished || $err === '') {
            return $usage;
        }
        throw new SfuKitError(0, "sfukit POST /ai/complete: $err");
    }

    /** @return array{settings:array, dailyCapUsd:float} */
    public function getAiSettings(): array
    {
        return $this->request('GET', '/ai/settings');
    }

    /** Replace AI Tasks' settings. Keys: dailyBudgetUsd, userMonthlyUsd, tiers, defaultTier, audit. */
    public function setAiSettings(array $settings): array
    {
        return $this->request('PUT', '/ai/settings', (object) $settings);
    }

    /** Spending (today against the cap, per day) and calls by task and model over `$days` (1-90). */
    public function getAiUsage(int $days = 7): array
    {
        return $this->request('GET', '/ai/usage?days=' . $days);
    }

    /**
     * One UTC day's calls (never their text), optionally one person's.
     * @return array{day:string, events:array, truncated:bool}
     */
    public function getAiAudit(?string $day = null, ?string $user = null): array
    {
        $q = array_filter(['day' => $day, 'user' => $user], static fn ($v) => $v !== null && $v !== '');
        return $this->request('GET', '/ai/audit' . ($q ? '?' . http_build_query($q) : ''));
    }

    // ---------- Speech (/speech/tts, /speech/stt) ----------

    /**
     * Speak `$text` (up to 5,000 characters): the audio file's bytes (mp3 unless format 'wav'). Repeats of the
     * same text come from the cache, free. Options: language (default 'en'; Yoruba 'yo', Hausa 'ha' and Igbo
     * 'ig' use Spitch or ElevenLabs), voice, provider ('cartesia', 'elevenlabs', 'spitch', 'openai', 'deepgram'),
     * format ('mp3' or 'wav'), speed (0.5 to 2), model, cache (false: always made fresh, always billed).
     */
    public function textToSpeech(string $text, array $options = []): string
    {
        return $this->request('POST', '/speech/tts', array_merge(['text' => $text], self::dropNulls($options), ['response' => 'audio']), true);
    }

    /**
     * Speak `$text` and get a signed link that plays without your key until it expires. Takes the options of
     * textToSpeech except cache, and expiresIn (60 seconds to 7 days, default 3600).
     * @return array{url:string, expiresAt:int, cached:bool, characters:int, provider:string, voice:string, format:string}
     */
    public function textToSpeechUrl(string $text, array $options = []): array
    {
        return $this->request('POST', '/speech/tts', array_merge(['text' => $text], self::dropNulls($options), ['response' => 'url']));
    }

    /**
     * Transcribe a recording (up to 25 MB, as a string of bytes). Options: contentType (e.g. 'audio/webm',
     * 'audio/wav', 'audio/mpeg'; default 'application/octet-stream'), language (unset: detected by Deepgram and
     * ElevenLabs), provider ('deepgram', 'elevenlabs', 'spitch'), model (a Deepgram model, default nova-3),
     * timeoutSeconds (default 120).
     * @return array{text:string, language?:string, seconds:int, provider:string, words?:array}
     */
    public function speechToText(string $audio, array $options = []): array
    {
        $q = array_filter(array_intersect_key($options, ['language' => 1, 'provider' => 1, 'model' => 1]), static fn ($v) => $v !== null && $v !== '');
        return $this->call(
            'POST',
            '/speech/stt' . ($q ? '?' . http_build_query($q) : ''),
            $audio,
            (string) ($options['contentType'] ?? 'application/octet-stream'),
            (int) ($options['timeoutSeconds'] ?? 120)
        );
    }

    /** Speech spending per day (US dollars) and today's cap: ['today' => […], 'days' => […]]. */
    public function getSpeechUsage(): array
    {
        return $this->request('GET', '/speech/usage');
    }

    /** The project's own daily speech cap in US dollars (null = the server's default). */
    public function setSpeechDailyBudget(?float $dailyBudgetUsd): array
    {
        return $this->request('PUT', '/speech/config', ['dailyBudgetUsd' => $dailyBudgetUsd]);
    }

    // ---------- Videos (video on demand on R2) ----------

    /** Seconds a video upload may take (a whole file, or one part of it); longer than the client's timeout. */
    public const UPLOAD_TIMEOUT_SECONDS = 600;

    /**
     * An upload link for one video: give `uploadUrl` to the browser (it PUTs the file straight to it, or its
     * parts when `protocol` is 'parts'), or send a file from your server with uploadVideoFile. Keys:
     * maxDurationSeconds (required: the longest the video may be; longer ones are refused when converted),
     * name, meta (up to 10 string pairs of your own), uploadLength (the file's size in bytes; required over
     * 95 MB, up to 5 GB).
     * @return array{videoId:string, uploadUrl:string, protocol:string, partSize?:int, parts?:int, expiresAt:string}
     */
    public function createVideoUpload(array $request): array
    {
        if (!isset($request['maxDurationSeconds'])) {
            throw new SfuKitError(400, 'maxDurationSeconds is required');
        }
        return $this->request('POST', '/videos/uploads', self::withMeta(self::dropNulls($request)));
    }

    /**
     * Send a file from your server to an upload link (both protocols): one PUT, or each part in turn and then
     * the finishing POST. `$file` is a string of bytes, or a readable stream (read in parts for big files).
     * Returns the video.
     * @param string|resource $file
     */
    public function uploadVideoFile(array $upload, mixed $file): array
    {
        $stream = is_resource($file) ? $file : null;
        if ($stream === null && !is_string($file)) {
            throw new SfuKitError(400, 'file must be a string of bytes or a readable stream');
        }
        $url = (string) ($upload['uploadUrl'] ?? '');
        $timeout = max($this->timeoutSeconds, self::UPLOAD_TIMEOUT_SECONDS);
        if (($upload['protocol'] ?? '') === 'put') {
            return $this->upload('PUT', $url, $stream !== null ? (string) stream_get_contents($stream) : $file, null, $timeout);
        }
        $size = (int) ($upload['partSize'] ?? 50 * 1024 * 1024);
        if ($size < 1) {
            throw new SfuKitError(400, 'invalid partSize');
        }
        $sep = str_contains($url, '?') ? '&' : '?';
        $parts = [];
        for ($n = 1, $at = 0;; $n++, $at += $size) {
            $chunk = $stream !== null ? self::readPart($stream, $size) : (string) substr($file, $at, $size);
            if ($chunk === '') {
                break;
            }
            $parts[] = $this->upload('PUT', "$url{$sep}part=$n", $chunk, null, $timeout);
        }
        return $this->upload('POST', "$url{$sep}complete", Json::encode(['parts' => $parts]), 'application/json', $timeout);
    }

    /** Bring in a video from a public https link (moving an existing library over). Options: name, meta, maxDurationSeconds. */
    public function copyVideo(string $url, array $options = []): array
    {
        return $this->request('POST', '/videos/copy', ['url' => $url] + self::withMeta(self::dropNulls($options)));
    }

    /**
     * Your videos, newest first: ['videos' => […], 'next' => …]. Options: after (the `next` of the last page), limit.
     */
    public function listVideos(array $options = []): array
    {
        $q = array_filter(array_intersect_key($options, ['after' => 1, 'limit' => 1]), static fn ($v) => $v !== null && $v !== '');
        return $this->request('GET', '/videos' . ($q ? '?' . http_build_query($q) : ''));
    }

    public function getVideo(string $videoId): array
    {
        return $this->request('GET', '/videos/' . self::id($videoId));
    }

    /** Rename (name), or replace your meta (meta). */
    public function updateVideo(string $videoId, array $update): array
    {
        return $this->request('PATCH', '/videos/' . self::id($videoId), (object) self::withMeta($update));
    }

    /** Delete the video and its files for good. */
    public function deleteVideo(string $videoId): void
    {
        $this->request('DELETE', '/videos/' . self::id($videoId));
    }

    /**
     * Signed links for one viewer. Make one per viewing; they expire. Options: expiresInSeconds (5 minutes to 24 hours).
     * @return array{videoId:string, hls:string, iframe:string, thumbnail:string, expiresAt:string}
     */
    public function videoPlayback(string $videoId, array $options = []): array
    {
        return $this->request('POST', '/videos/' . self::id($videoId) . '/playback', (object) self::dropNulls($options));
    }

    /** Bytes kept, this month's meters and your caps. */
    public function getVideoUsage(): array
    {
        return $this->request('GET', '/videos/usage');
    }

    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    // ---------- plumbing ----------

    /** Call any admin endpoint (for routes this SDK doesn't wrap yet). */
    public function request(string $method, string $path, mixed $body = null, bool $raw = false): mixed
    {
        return $this->call($method, $path, $body === null ? null : Json::encode($body), 'application/json', $this->timeoutSeconds, $raw);
    }

    /** One admin call with an already encoded body (`$contentType` says what it is). */
    private function call(string $method, string $path, ?string $payload, string $contentType, int $timeoutSeconds, bool $raw = false): mixed
    {
        [$status, $text, $err] = $this->exchange($method, $this->url . $path, $this->headers($payload === null ? null : $contentType), $payload, $timeoutSeconds);
        if ($text === false) {
            throw new SfuKitError(0, "sfukit $method $path: $err");
        }
        if ($status < 200 || $status >= 300) {
            throw self::httpError($method, $path, $status, $text);
        }
        if ($raw) {
            return $text;
        }
        return $text === '' ? null : Json::decode($text);
    }

    /** Send bytes to an upload link the kit gave. The link carries its own token, so the admin key is not sent. */
    private function upload(string $method, string $url, string $payload, ?string $contentType, int $timeoutSeconds): mixed
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new SfuKitError(400, "invalid upload link \"$url\"");
        }
        $headers = ['User-Agent: sfukit-php/' . self::VERSION];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        [$status, $text, $err] = $this->exchange($method, $url, $headers, $payload, $timeoutSeconds);
        if ($text === false) {
            throw new SfuKitError(0, "sfukit video upload: $err");
        }
        if ($status < 200 || $status >= 300) {
            throw self::httpError('video upload', '', $status, $text);
        }
        return $text === '' ? null : Json::decode($text);
    }

    /** @return list<string> */
    private function headers(?string $contentType): array
    {
        $headers = ['Authorization: Bearer ' . $this->adminKey, 'User-Agent: sfukit-php/' . self::VERSION];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        return $headers;
    }

    /**
     * The one place a request leaves the SDK (tests replace it).
     * @param list<string> $headers
     * @return array{0:int, 1:string|false, 2:string} status, body (false if nothing came back), curl's error
     */
    protected function exchange(string $method, string $url, array $headers, ?string $payload, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $opts);
        $text = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status, is_string($text) ? $text : false, $err];
    }

    /**
     * Like exchange(), for an answer read as it arrives: `$onChunk(status, chunk)` gets each piece of the body
     * (return false to stop reading). Tests replace it.
     * @param list<string> $headers
     * @param callable(int, string): bool $onChunk
     * @return array{0:int, 1:string} status, curl's error ('' if none)
     */
    protected function stream(string $method, string $url, array $headers, ?string $payload, int $timeoutSeconds, callable $onChunk): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($onChunk): int {
                return $onChunk((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $chunk) === false ? 0 : strlen($chunk);
            },
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status, $err];
    }

    private static function httpError(string $method, string $path, int $status, string $text): SfuKitError
    {
        try {
            $parsed = Json::decode($text);
        } catch (\JsonException) {
            $parsed = $text;
        }
        $msg = is_array($parsed) && isset($parsed['error']) ? $parsed['error'] : $text;
        return new SfuKitError($status, trim("sfukit $method $path") . ": $status $msg", $parsed);
    }

    /** Options without the ones left null. */
    private static function dropNulls(array $options): array
    {
        return array_filter($options, static fn ($v) => $v !== null);
    }

    /** A request body for /ai/complete: nulls left out, a JSON Schema kept as nested objects. */
    private static function aiBody(array $request): array
    {
        $body = self::dropNulls($request);
        if (isset($body['json']) && is_array($body['json'])) {
            $body['json'] = self::schemaObjects($body['json']);
        }
        return $body;
    }

    /** Send `meta` as a JSON object even when empty. */
    private static function withMeta(array $body): array
    {
        if (isset($body['meta']) && is_array($body['meta'])) {
            $body['meta'] = (object) $body['meta'];
        }
        return $body;
    }

    /** @param resource $stream */
    private static function readPart($stream, int $size): string
    {
        $buf = '';
        while (strlen($buf) < $size && !feof($stream)) {
            $piece = fread($stream, $size - strlen($buf));
            if ($piece === false || $piece === '') {
                break;
            }
            $buf .= $piece;
        }
        return $buf;
    }

    private static function id(string $value): string
    {
        // "." and ".." would be resolved as dot segments, sending the request to a different route.
        if (!preg_match(Tokens::ID, $value) || $value === '.' || $value === '..') {
            throw new SfuKitError(400, "invalid id \"$value\" (letters, numbers, . _ : @ -, max 128)");
        }
        return rawurlencode($value);
    }

    /** A JSON Schema with its empty maps ("properties": {}) kept as objects. */
    private static function schemaObjects(array $schema): object
    {
        foreach (['properties', 'patternProperties', '$defs', 'definitions'] as $k) {
            if (isset($schema[$k]) && is_array($schema[$k])) {
                $schema[$k] = (object) array_map(static fn ($v) => is_array($v) ? self::schemaObjects($v) : $v, $schema[$k]);
            }
        }
        if (isset($schema['items']) && is_array($schema['items']) && ($schema['items'] === [] || !array_is_list($schema['items']))) {
            $schema['items'] = self::schemaObjects($schema['items']);
        }
        return (object) $schema;
    }

    /** Send metadata and permission maps as JSON objects even when empty. */
    private static function objects(array $update): array
    {
        foreach (['metadata', 'permissions'] as $k) {
            if (isset($update[$k]) && is_array($update[$k])) {
                $update[$k] = (object) $update[$k];
            }
        }
        return $update;
    }
}
