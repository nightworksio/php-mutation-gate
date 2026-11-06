<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * When a run samples by itself (ADR-0086): never, above as many mutants as
 * a plan expects to run beyond its new code, or above as long a runner time
 * as it expects them to take: `sampling.auto`.
 */
final readonly class AutoSample
{
    /** The word that turns sampling of its own accord off. */
    public const string OFF = 'off';

    private function __construct(private int|Seconds|NotGiven $above)
    {
    }

    public static function off(): self
    {
        return new self(NotGiven::value());
    }

    /** Above this many mutants beyond the new code. */
    public static function aboveMutants(int $mutants): self
    {
        return new self($mutants);
    }

    /** Above this much runner time beyond the new code. */
    public static function aboveRunnerTime(Seconds $runnerTime): self
    {
        return new self($runnerTime);
    }

    public function isOff(): bool
    {
        return $this->above instanceof NotGiven;
    }

    /** Whether a plan that expects this work beyond its new code samples it. */
    public function samples(ExpectedWork $work): bool
    {
        return match (true) {
            $this->above instanceof NotGiven => false,
            $this->above instanceof Seconds => $work->runnerTime()->seconds() > $this->above->seconds(),
            default => $work->mutants() > $this->above,
        };
    }

    /** The threshold as the plan says it: `20000 mutants`, `2h of runner time`. */
    public function said(): string
    {
        return match (true) {
            $this->above instanceof NotGiven => self::OFF,
            $this->above instanceof Seconds => sprintf('%s of runner time', $this->above->text()),
            default => sprintf('%d %s', $this->above, $this->above === 1 ? 'mutant' : 'mutants'),
        };
    }

    /** As a config writes it: `off`, a count, or a duration. */
    public function written(): string|int
    {
        return match (true) {
            $this->above instanceof NotGiven => self::OFF,
            $this->above instanceof Seconds => $this->above->written(),
            default => $this->above,
        };
    }
}
