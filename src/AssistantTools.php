<?php

declare(strict_types=1);

namespace SfuKit;

/**
 * Your assistant's toolsUrl: checks each request's signature and runs the matching handler
 * as the person who asked.
 *
 *   $tools = new SfuKit\AssistantTools(getenv('APPSOCOM_WEBHOOK_SECRET'), [
 *       'list_students' => fn (array $args, array $call) => $db->students($call['metadata']['school'], $args['cls']),
 *   ]);
 *   [$status, $body] = $tools->handle(file_get_contents('php://input'), $_SERVER['HTTP_X_SFUKIT_SIGNATURE'] ?? null);
 *   http_response_code($status);
 *   header('Content-Type: application/json');
 *   echo $body;
 *
 * A handler gets the arguments and the call (conversationId, userId, role, callId, name, metadata).
 * What it returns goes back to the model (keep it small: at most 16 KB is read); null sends "done".
 * Throw AssistantToolError to show the model a message; any other exception is hidden as
 * "The tool failed." and passed to $onError.
 */
final class AssistantTools
{
    /** @var array<string, callable> */
    private array $handlers;
    /** @var (callable(\Throwable, array): void)|null */
    private $onError;

    /**
     * @param string $secret your webhook secret
     * @param array<string, callable(array, array): mixed> $handlers tool name => handler
     */
    public function __construct(
        private readonly string $secret,
        array $handlers,
        private readonly int $toleranceSeconds = 300,
        ?callable $onError = null,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('secret is required (your webhook secret)');
        }
        $this->handlers = $handlers;
        $this->onError = $onError;
    }

    /**
     * Check a tool call's signature. Returns the call, or null when it is wrong, stale or not an assistant call.
     *
     * @return array<string,mixed>|null
     */
    public static function verify(string $secret, string $rawBody, ?string $signatureHeader, int $toleranceSeconds = 300): ?array
    {
        $call = Webhooks::verify($secret, $rawBody, $signatureHeader, $toleranceSeconds);
        if ($call === null || ($call['type'] ?? null) !== 'tool_call' || ($call['kind'] ?? null) !== 'assistant' || !is_string($call['name'] ?? null)) {
            return null;
        }
        $call['arguments'] = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
        $call['metadata'] = is_array($call['metadata'] ?? null) ? $call['metadata'] : [];
        return $call;
    }

    /**
     * Check, run and answer one call. Pass the RAW body.
     *
     * @return array{0:int, 1:string} [HTTP status, JSON body]
     */
    public function handle(string $rawBody, ?string $signatureHeader): array
    {
        $call = self::verify($this->secret, $rawBody, $signatureHeader, $this->toleranceSeconds);
        if ($call === null) {
            return [401, Json::encode(['error' => 'bad signature'])];
        }
        $name = $call['name'];
        if (!array_key_exists($name, $this->handlers) || !is_callable($this->handlers[$name])) {
            return [200, Json::encode(['error' => "There is no tool called $name."])];
        }
        try {
            $result = ($this->handlers[$name])($call['arguments'], $call);
            return [200, Json::encode(['result' => $result ?? 'done'])];
        } catch (AssistantToolError $e) {
            return [200, Json::encode(['error' => $e->getMessage()])];
        } catch (\Throwable $e) {
            if ($this->onError !== null) {
                try {
                    ($this->onError)($e, $call);
                } catch (\Throwable) {
                }
            }
            return [200, Json::encode(['error' => 'The tool failed.'])];
        }
    }
}
