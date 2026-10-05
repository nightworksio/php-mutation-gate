<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use NightWorksIO\MutationGate\Cli\ExitCode;

/** What `deliver` sent, a line for each part of the delivery, and how it ends. */
final readonly class Sent
{
    /** @param list<string> $said */
    private function __construct(private array $said, private ExitCode $exit)
    {
    }

    /** @param list<string> $said */
    public static function of(array $said, ExitCode $exit): self
    {
        return new self($said, $exit);
    }

    /** @return list<string> */
    public function said(): array
    {
        return $this->said;
    }

    public function exit(): ExitCode
    {
        return $this->exit;
    }
}
