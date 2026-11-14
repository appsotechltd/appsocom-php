<?php

declare(strict_types=1);

namespace SfuKit;

/** @internal JSON with the same bytes as JavaScript's JSON.stringify for our payloads. */
final class Json
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $text): mixed
    {
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
}
