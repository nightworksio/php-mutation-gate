<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Score\Floor;
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

    public function __construct(private Baselines $baselines)
    {
    }

    /** @return list<string>|CannotJudge what was raised, a line a tree, or why the baseline cannot be written */
    public function raise(Baseline $committed, TreeVerdicts $verdicts): array|CannotJudge
    {
        $lines = [];

        foreach ($verdicts as $verdict) {
            $raised = $verdict->raised();
            $was = $committed->floorOf($verdict->tree()->path());
            $lines = $raised instanceof Floor
                ? [...$lines, sprintf(
                    '  %s: %s, was %s',
                    $verdict->tree()->path()->value(),
                    BaselineFile::number($raised),
                    $was instanceof Floor ? BaselineFile::number($was) : 'none',
                )]
                : $lines;
        }

        if ($lines === []) {
            return [sprintf(self::NONE, $this->baselines->file()->value())];
        }

        $written = $this->baselines->write($committed->raisedBy($verdicts));

        return $written instanceof CannotJudge
            ? $written
            : [sprintf(self::RAISED, $this->baselines->file()->value()), ...$lines];
    }
}
