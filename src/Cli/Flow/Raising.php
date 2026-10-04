<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

use function sprintf;

/**
 * Writes every floor a verdict raised, and every missing one at its measured
 * score, into the committed baseline, and says which lines to commit. A floor
 * is never lowered here.
 */
final readonly class Raising
{
    private const string RAISED = 'Raised the floors in %s. Commit it:';

    private const string NONE = 'No floor in %s rose.';

    /** A floor raised: what it is of, the floor, and the one it was. */
    private const string LINE = '  %s: %s, was %s';

    public function __construct(private Baselines $baselines)
    {
    }

    /**
     * @return list<string>|CannotJudge what was raised, a line a tree or security set, or why the baseline cannot
     *                                  be written
     */
    public function raise(Baseline $committed, TreeVerdicts $verdicts, SecurityVerdicts $security): array|CannotJudge
    {
        $lines = [];

        foreach ($verdicts as $verdict) {
            $was = $committed->entryOf($verdict->tree()->path());
            $lines = [...$lines, ...$this->line($verdict->tree()->path()->value(), $verdict->raised(), $was)];
        }

        foreach ($security as $verdict) {
            $package = $verdict->package()->path();
            $named = sprintf(SecurityVerdict::SET, $package->value());
            $lines = [...$lines, ...$this->line($named, $verdict->raised(), $committed->securityOf($package))];
        }

        if ($lines === []) {
            return [sprintf(self::NONE, $this->baselines->file()->value())];
        }

        $written = $this->baselines->write($committed->raisedBy($verdicts, $security));

        return $written instanceof CannotJudge
            ? $written
            : [sprintf(self::RAISED, $this->baselines->file()->value()), ...$lines];
    }

    /** @return list<string> the line of a floor a score raised; none where it raised none */
    private function line(string $named, Floor|Unraised $raised, Entry|Unrecorded $was): array
    {
        return $raised instanceof Floor
            ? [sprintf(
                self::LINE,
                $named,
                BaselineFile::number($raised),
                $was instanceof Entry ? BaselineFile::number($was->floor()) : 'none',
            )]
            : [];
    }
}
