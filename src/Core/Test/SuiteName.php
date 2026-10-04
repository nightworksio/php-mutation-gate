<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/**
 * The name of one `<testsuite>` of the PHPUnit config, which `--suite`
 * narrows a run's tests to (ADR-0025, decision 9).
 */
final readonly class SuiteName
{
    private function __construct(private string $name)
    {
    }

    public static function of(string $name): self
    {
        return new self($name);
    }

    public function value(): string
    {
        return $this->name;
    }
}
