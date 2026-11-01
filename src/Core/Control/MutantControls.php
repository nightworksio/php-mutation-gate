<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The unmutated control each of some mutants is judged by, by the mutant's
 * gate id (see Control), each control asked for once, however many mutants
 * share it.
 *
 * @internal
 */
final readonly class MutantControls
{
    /** @param array<string, Control> $controls by each mutant's gate id */
    private function __construct(private array $controls)
    {
    }

    /** @param array<string, Control> $controls by each mutant's gate id */
    public static function of(array $controls): self
    {
        return new self($controls);
    }

    /** The control a mutant is judged by; none where it has none. */
    public function controlOf(Mutant $mutant): Control|NotGiven
    {
        $key = $mutant->id()->key();

        return array_key_exists($key, $this->controls) ? $this->controls[$key] : NotGiven::value();
    }

    /** Every control asked for, each once. */
    public function asked(): Controls
    {
        return Controls::of(...$this->controls);
    }

    /**
     * The mutants, each with a control judged so by what it found; unjudged,
     * the time budget having run out before its control, where its control is
     * among those left; and the rest as they are.
     */
    public function applied(Mutants $mutants, ControlRuns $runs, Controls $left, ControlJudge $judge): Mutants
    {
        $applied = [];

        foreach ($mutants as $mutant) {
            $key = $mutant->id()->key();
            $applied[] = match (true) {
                ! array_key_exists($key, $this->controls) => $mutant,
                $left->has($this->controls[$key]) => $mutant->unjudged(OutOfTime::BeforeControlling),
                default => $judge->judged($mutant, $runs->of($this->controls[$key]), $this->controls[$key]),
            };
        }

        return Mutants::of(...$applied);
    }
}
