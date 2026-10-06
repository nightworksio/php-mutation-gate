<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function array_map;
use function getmypid;
use function implode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function strval;

/**
 * The runs that vouch for a narrowed run's kills (see NarrowedKills): the
 * tests of each set of files a mutant's own run was narrowed to, loaded alone
 * on the unmutated code as that run loaded them, once for each set of files
 * in a process, side by side in the places of the request's pool as the
 * mutants' own runs were.
 */
final readonly class AloneRuns
{
    /** Where the parts of a run's key are joined. */
    private const string BETWEEN = "\n";

    public function __construct(private Project $project, private Shell $shell, private Remembered $remembered)
    {
    }

    /**
     * Whether the tests of each set of files, by their paths on disk, pass,
     * in the order given: none where no time is left, and none started once
     * the time left has run out.
     *
     * @param  non-empty-list<list<string>> $sets
     * @return list<bool>
     */
    public function pass(array $sets, MutationRequest $request, Seconds|Unlimited $left): array
    {
        if ($left instanceof Seconds && $left->seconds() <= 0.0) {
            return array_map(static fn(): bool => false, $sets);
        }

        $withheld = $request->withheld();
        $invocation = Invocation::installedIn($this->project->vendor());
        $commands = [];

        foreach ($sets as $files) {
            $judging = $invocation
                ->judging(Paths::of(...array_map(Path::of(...), $files)), $request->judgedBy(), $withheld)
                ->within($left);
            $commands[implode(self::BETWEEN, [$withheld->pattern(), ...$judging->arguments()])] = $judging;
        }

        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));

        return $this->remembered->baselines(
            array_keys($commands),
            /** @param non-empty-list<string> $keys */
            fn(array $keys): ProcessEnds => $this->shell->sideBySide(
                $slots,
                $left,
                ...array_map(static fn(string $key): Command => $commands[$key], $keys),
            ),
        );
    }
}
