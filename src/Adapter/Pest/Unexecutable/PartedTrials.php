<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_keys;

/**
 * The trials of several mutants, each in its parts (see PartedTrial), by
 * position: each mutant's next part run side by side with the others' in a
 * round, round after round, until each is judged.
 */
final readonly class PartedTrials
{
    /** @param array<int, PartedTrial> $parted */
    private function __construct(private array $parted)
    {
    }

    /** @param array<int, PartedTrial> $parted each mutant's trials, by position */
    public static function of(array $parted): self
    {
        return new self($parted);
    }

    /**
     * What each mutant's trials find, by position.
     *
     * @return array<int, Outcome>
     */
    public function triedBy(Trial $trial): array
    {
        $parted = $this->parted;

        for ($asked = $this->asked($parted); $asked !== []; $asked = $this->asked($parted)) {
            $at = array_keys($asked);

            foreach ($trial->ofEach(...$asked) as $position => $outcome) {
                $parted[$at[$position]] = $parted[$at[$position]]->after($outcome);
            }
        }

        $found = [];

        foreach ($parted as $at => $each) {
            $outcome = $each->outcome();
            $found += $outcome instanceof Outcome ? [$at => $outcome] : [];
        }

        return $found;
    }

    /**
     * The trial each mutant not yet judged waits on, by position.
     *
     * @param  array<int, PartedTrial> $parted
     * @return array<int, TrialRun>
     */
    private function asked(array $parted): array
    {
        $asked = [];

        foreach ($parted as $at => $each) {
            $next = $each->next();
            $asked += $next instanceof TrialRun ? [$at => $next] : [];
        }

        return $asked;
    }
}
