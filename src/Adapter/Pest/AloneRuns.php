<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function dirname;
use function getmypid;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function strval;

/**
 * The runs that vouch for kills on the unmutated code (see Control): the
 * tests of each set of files a mutant's own run was narrowed to (see
 * NarrowedKills), or the tests a run again with every test file ran, each
 * with the file its mutant changes served unmutated through Pest's override,
 * as the mutant's run served its copy. Each runs once for each key in a
 * process, side by side in the places of the request's pool as the mutants'
 * own runs were.
 */
final readonly class AloneRuns
{
    /** Where the parts of a run's key are joined. */
    private const string BETWEEN = "\n";

    public function __construct(private Project $project, private Shell $shell, private Remembered $remembered)
    {
    }

    /**
     * Whether each control passes, in the order given: none where no time is
     * left, none started once the time left has run out, and none whose file
     * cannot be served unmutated.
     *
     * @param  non-empty-list<Control> $controls
     * @param  string                  $results  the run's results file, beside which the unmutated copies are written
     * @return list<bool>
     */
    public function pass(array $controls, MutationRequest $request, Seconds|Unlimited $left, string $results): array
    {
        if ($left instanceof Seconds && $left->seconds() <= 0.0) {
            return array_map(static fn(): bool => false, $controls);
        }

        $withheld = $request->withheld();
        $invocation = Invocation::installedIn($this->project->vendor());
        $commands = [];
        $keys = [];

        foreach ($controls as $at => $control) {
            $served = ServedOriginal::of($this->project, dirname($results), $control->source());

            if ($served instanceof CannotJudge) {
                continue;
            }

            $judging = $served->onto(
                $invocation->judging($control->tests(), $control->judgedBy(), $withheld)->within($left),
            );
            $keys[$at] = implode(self::BETWEEN, [$withheld->pattern(), $served->key(), ...$judging->arguments()]);
            $commands[$keys[$at]] = $judging;
        }

        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));
        $ran = $commands === [] ? [] : $this->remembered->baselines(
            array_keys($commands),
            /** @param non-empty-list<string> $unknown */
            fn(array $unknown): ProcessEnds => $this->shell->sideBySide(
                $slots,
                $left,
                ...array_map(static fn(string $key): Command => $commands[$key], $unknown),
            ),
        );
        $passed = array_combine(array_keys($commands), $ran);

        return array_map(
            static fn(int $at): bool => array_key_exists($at, $keys) && $passed[$keys[$at]],
            array_keys($controls),
        );
    }
}
