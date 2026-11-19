<?php

declare(strict_types=1);

namespace SfuKit;

/** Throw this from an assistant tool to tell the model what went wrong (its message is shown to the model). */
class AssistantToolError extends \RuntimeException
{
}
