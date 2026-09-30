<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/**
 * The variable a CI sets in every job it runs, by which a job tells the CI
 * it runs in: set to `true`, or set to anything at all, as Azure Pipelines
 * sets `TF_BUILD` to `True`. The JSON plan's CI has none.
 */
final readonly class CiMarker
{
    private function __construct(private string $variable, private Marking $marking)
    {
    }

    /** A CI that sets this variable to `true`, as GitHub Actions sets `GITHUB_ACTIONS`. */
    public static function saying(string $variable): self
    {
        return new self($variable, Marking::SaysTrue);
    }

    /** A CI that sets this variable to any value, as Jenkins sets `JENKINS_URL`. */
    public static function setting(string $variable): self
    {
        return new self($variable, Marking::IsSet);
    }

    /** No CI's: a plan every job may take where no other plan's CI is marked. */
    public static function none(): self
    {
        return new self('', Marking::Never);
    }

    /** The variable's name. */
    public function variable(): string
    {
        return $this->variable;
    }

    public function marking(): Marking
    {
        return $this->marking;
    }
}
