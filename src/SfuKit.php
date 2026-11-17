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
    public const VERSION = '0.6.3';
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

    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    // ---------- plumbing ----------

    /** Call any admin endpoint (for routes this SDK doesn't wrap yet). */
    public function request(string $method, string $path, mixed $body = null, bool $raw = false): mixed
    {
        $headers = ['Authorization: Bearer ' . $this->adminKey, 'User-Agent: sfukit-php/' . self::VERSION];
        $ch = curl_init($this->url . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = Json::encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $text = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($text === false) {
            throw new SfuKitError(0, "sfukit $method $path: $err");
        }
        if ($status < 200 || $status >= 300) {
            try {
                $parsed = Json::decode($text);
            } catch (\JsonException) {
                $parsed = $text;
            }
            $msg = is_array($parsed) && isset($parsed['error']) ? $parsed['error'] : $text;
            throw new SfuKitError($status, "sfukit $method $path: $status $msg", $parsed);
        }
        if ($raw) {
            return $text;
        }
        return $text === '' ? null : Json::decode($text);
    }

    private static function id(string $value): string
    {
        if (!preg_match(Tokens::ID, $value)) {
            throw new SfuKitError(400, "invalid id \"$value\" (letters, numbers, . _ : @ -, max 128)");
        }
        return rawurlencode($value);
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
