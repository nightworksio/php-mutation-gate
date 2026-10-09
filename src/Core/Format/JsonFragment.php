<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function is_scalar;
use function json_decode;
use function json_encode;

use NightWorksIO\MutationGate\Core\NotGiven;

use function str_starts_with;

/**
 * A JSON value's text as a file writes it, its lines after the first
 * indented from the line its key stands on, so it can be written under any
 * key at any depth.
 */
final readonly class JsonFragment
{
    private const string OBJECT = '{';

    private function __construct(private string $text)
    {
    }

    /** A value's text, which must be JSON, its lines after the first indented from its key's line. */
    public static function of(string $json): self
    {
        return new self($json);
    }

    /** A scalar written as JSON writes it. */
    public static function encoding(string|int|float|bool $value): self
    {
        return new self(json_encode($value, JsonText::FLAGS));
    }

    public function text(): string
    {
        return $this->text;
    }

    public function isObject(): bool
    {
        return str_starts_with($this->text, self::OBJECT);
    }

    /** Whether it holds the same value as another, however each is laid out. */
    public function equals(self $other): bool
    {
        return json_decode($this->text, associative: true) === json_decode($other->text, associative: true);
    }

    /** The string, number or truth it holds; none where it holds an object, an array or `null`. */
    public function scalar(): string|int|float|bool|NotGiven
    {
        $value = json_decode($this->text);

        return is_scalar($value) ? $value : NotGiven::value();
    }
}
