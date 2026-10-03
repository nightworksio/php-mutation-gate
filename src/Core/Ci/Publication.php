<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Written;

/**
 * A plan in a CI's own format, and where it goes (ADR-0006 decision 5): the
 * text, the file, and whether it is printed, written or appended there. A CI
 * plan says what and where; the command writes it.
 */
final readonly class Publication
{
    private function __construct(private string $text, private string $to, private Delivery $delivery)
    {
    }

    /** This text, printed to the command's output, where a CI reads it from a pipe or a log. */
    public static function printed(string $text): self
    {
        return new self($text, Written::OUTPUT, Delivery::Printed);
    }

    /** This text, as the whole of this file, in a directory made where there is none. */
    public static function written(string $file, string $text): self
    {
        return new self($text, $file, Delivery::Written);
    }

    /** This text, added to the end of this file, as `$GITHUB_OUTPUT` takes it. */
    public static function appended(string $file, string $text): self
    {
        return new self($text, $file, Delivery::Appended);
    }

    public function text(): string
    {
        return $this->text;
    }

    /** Where it goes: a file, or the command's output. */
    public function to(): string
    {
        return $this->to;
    }

    public function delivery(): Delivery
    {
        return $this->delivery;
    }
}
