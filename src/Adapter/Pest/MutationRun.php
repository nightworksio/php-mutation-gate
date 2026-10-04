<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function array_values;

use Closure;

use function count;
use function dirname;
use function implode;

use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Judging;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * One Pest mutation run, from a fresh results file to the gate's records: a
 * patched shard opens on the canary group with the map of its own files the
 * plan handed over, which the run then reads its mutants' covering tests
 * from, and judges its mutants on lines that are not executable by the plan's
 * whole map; every other run reads its own opening map once, for both.
 */
final readonly class MutationRun
{
    private const string BY_GROUP_ALONE
        = 'Pest selects held tests by the holds: groups its plugin adds for #[Holds], not by the filter %s.';

    private const string COMMA = "Pest's --path and --ignore split on commas, so Pest cannot mutate %s less %s.";

    private const string NOT_PATCHED
        = 'pest.patch is on, but pest-plugin-mutate in %s is not patched. Run mutation-gate pest:patch.';

    private const string EMPTY_CANARY = 'pest.patch is on, but the canary group %s holds no test. Add one.';

    /** Why a narrowed run's doubtful kill is unjudged where no time is left to run it again. */
    private const string NO_TIME_TO_CONFIRM
        = "Killed where its covering tests' files alone cannot vouch for the kill; no time is left to run them all.";

    /** Why such a mutant is unjudged where the run again made no mutant with its id. */
    private const string NOT_MADE_TO_CONFIRM = 'Run again with every test file, Pest made no mutant with this id.';

    /** Where the parts of a baseline's key are joined. */
    private const string BETWEEN = "\n";

    /** Where the map another job handed over is written again for this job's Pest, beside the results. */
    private const string SHARED_MAP = '%s/shared.coverage.php';

    /**
     * @param Closure(Withheld): (Groups|CannotJudge) $groups the suite's groups, as the runner lists them
     * @param list<string>                            $only   the native ids of the only mutants a patched run
     *                                                        makes, or none for every mutant it finds
     */
    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private Patching $patching,
        private Remembered $remembered,
        private Closure $groups,
        private array $only = [],
        private Clock $clock = new WallClock(),
        private bool $whole = false,
        private Bridges $bridges = new Bridges(),
    ) {
    }

    /**
     * This run, making only the mutants with these native ids where
     * pest-plugin-mutate is patched; unpatched, it makes every mutant of its
     * files and mutators, and the caller matches back the ones it asked for.
     */
    public function only(string ...$nativeIds): self
    {
        return clone($this, ['only' => array_values($nativeIds)]);
    }

    /**
     * This run, in which each mutant's own run loads every test file, as
     * Pest ships it, where a patched run narrows it.
     */
    public function whole(): self
    {
        return clone($this, ['whole' => true]);
    }

    /**
     * Every mutant of the requested files, where there are any to mutate:
     * Pest's `--path` never names none. Where a patched run narrowed each
     * mutant's own run to the test files its covering tests need, a kill
     * those files alone cannot vouch for (see NarrowedKills) runs again with
     * every test file before it counts, within the time left, or is unjudged
     * where none is.
     */
    public function of(MutationRequest $request): MutationResult|CannotJudge
    {
        if (count($request->files()) === 0 || $this->bridges->appliesNone($request->narrowing()->mutators())) {
            return MutationResult::of(Mutants::none(), 0);
        }

        $started = $this->clock->seconds();
        $results = $this->project->freshResults();

        if ($results instanceof CannotJudge) {
            return $results;
        }

        $shared = $this->shared($request);
        $result = $shared instanceof CannotJudge ? $shared : $this->ran($request, $results, $shared);

        return $result instanceof CannotJudge || ! $this->narrows()
            ? $result
            : $this->confirmed($result, $request, $started, $this->doubted($result, $request, $results, $started));
    }

    /** A narrowed run's kills that must run again with every test file before they count (see NarrowedKills). */
    private function doubted(MutationResult $result, MutationRequest $request, string $results, float $started): Mutants
    {
        return NarrowedKills::in($result, $results)->doubted(
            /** @param list<string> $files */
            fn(array $files): bool => $this->passesAlone($files, $request, $started),
        );
    }

    private function ran(
        MutationRequest $request,
        string $results,
        CoverageMap|Unshared $shared,
    ): MutationResult|CannotJudge {
        $command = Plan::handedOver($this->project, $request, $this->commandFor($request, $results, $shared));
        $scan = MemoryScan::beside($this->project, $results, $request->memory(), $this->files);

        if ($command instanceof CannotJudge || $scan instanceof CannotJudge) {
            return $command instanceof CannotJudge ? $command : $scan;
        }

        $command = $scan->onto($command);
        $only = $this->only === [] ? [] : [
            GateVariable::Only->value => OnlyList::write(OnlyList::beside($results), ...$this->only),
        ];
        $narrow = $this->narrows() ? [GateVariable::Narrow->value => '1'] : [];
        $ran = $this->shell->run($command->with([...$only, ...$narrow]));
        $scan->remove();
        $coverage = $shared instanceof CoverageMap
            ? new HandedOver($shared, $this->project)
            : CoverageFile::at(Recorder::coverageBeside($results));
        $interpretation = new Interpretation($this->project, $this->patching, $request->memory(), $this->bridges);
        $result = $interpretation->of($ran, $results, $coverage);

        if ($result instanceof CannotJudge || $coverage instanceof CannotJudge) {
            return $result;
        }

        $reads = new WholeMap($this->project, $this->remembered)->covering($request, $coverage, $shared);

        return $reads instanceof CannotJudge
            ? $reads
            : new Judging($this->project, $this->shell, $this->files)->of($result, $request, $results, $reads);
    }

    /** Whether each mutant's own run loads only the test files its covering tests need. */
    private function narrows(): bool
    {
        return $this->patching->isOn() && ! $this->whole;
    }

    /**
     * The result, each doubtful kill run again with every test file, within
     * the time left since the run began, or unjudged where none is left.
     */
    private function confirmed(
        MutationResult $result,
        MutationRequest $request,
        float $started,
        Mutants $doubtful,
    ): MutationResult|CannotJudge {
        if (count($doubtful) === 0) {
            return $result;
        }

        $left = $this->left($request, $started);
        $again = $left instanceof Seconds && $left->seconds() <= 0.0
            ? FoundAgain::among($doubtful, Mutants::none(), Reason::that(self::NO_TIME_TO_CONFIRM))
            : $this->whole()->again($doubtful, $left instanceof Seconds ? $request->within($left) : $request);

        return $again instanceof CannotJudge ? $again : MutationResult::of(
            FoundAgain::replacing($result->mutants(), $again),
            $result->skipped(),
        );
    }

    /** The time left of the request's deadline since the run began. */
    private function left(MutationRequest $request, float $started): Seconds|Unlimited
    {
        $deadline = $request->deadline();

        return $deadline instanceof Seconds
            ? Seconds::of($deadline->seconds() - ($this->clock->seconds() - $started))
            : $deadline;
    }

    /**
     * Whether the tests of the files a mutant's own run was narrowed to, by
     * their paths on disk, pass on the unmutated code, loaded alone as that
     * run loaded them, within the time left: once for each set of files.
     *
     * @param list<string> $files
     */
    private function passesAlone(array $files, MutationRequest $request, float $started): bool
    {
        $left = $this->left($request, $started);

        if ($left instanceof Seconds && $left->seconds() <= 0.0) {
            return false;
        }

        $withheld = $request->withheld();
        $judging = Invocation::installedIn($this->project->vendor())
            ->judging(Paths::of(...array_map(Path::of(...), $files)), $request->judgedBy(), $withheld)
            ->within($left);
        $key = implode(self::BETWEEN, [$withheld->pattern(), ...$judging->arguments()]);

        return $this->remembered->baseline($key, fn(): Ran => $this->shell->run($judging));
    }

    /** These mutants made again over their files with their mutators, each matched to the one asked for. */
    private function again(Mutants $mutants, MutationRequest $request): Mutants|CannotJudge
    {
        $files = [];
        $mutators = [];
        $natives = [];

        foreach ($mutants as $mutant) {
            $files[$mutant->location()->file()->value()] = $mutant->location()->file();
            $mutators[$mutant->mutation()->mutator()] = $mutant->mutation()->mutator();
            $natives[] = $mutant->nativeId();
        }

        $result = $this->only(...$natives)->of(
            $request->narrowedTo(
                Paths::of(...array_values($files)),
                $request->narrowing()->toMutators(Mutators::named(...array_values($mutators))),
            ),
        );

        return $result instanceof CannotJudge
            ? $result
            : FoundAgain::among($mutants, $result->mutants(), Reason::that(self::NOT_MADE_TO_CONFIRM));
    }

    private function commandFor(
        MutationRequest $request,
        string $results,
        CoverageMap|Unshared $shared,
    ): Command|CannotJudge {
        $judgedBy = $request->judgedBy();
        $files = PathList::of($request->files());
        $leftOut = PathList::of($request->leftOut());

        return match (true) {
            $judgedBy instanceof Filter => CannotJudge::because(sprintf(self::BY_GROUP_ALONE, $judgedBy->pattern())),
            $files->holdsAComma() || $leftOut->holdsAComma() => CannotJudge::because(
                sprintf(self::COMMA, $files->joined(', '), $leftOut->joined(', ')),
            ),
            default => $this->bridges->loading(
                $this->project,
                $this->opened(
                    Invocation::installedIn($this->project->vendor())
                        ->mutation($request, $judgedBy, $results, $this->bridges),
                    $results,
                    $shared,
                ),
            ),
        };
    }

    /** A run that opens on the canary group with the map another job handed over, where it has one. */
    private function opened(Command $command, string $results, CoverageMap|Unshared $shared): Command
    {
        if (! $shared instanceof CoverageMap) {
            return $command;
        }

        $map = sprintf(self::SHARED_MAP, dirname($results));
        $this->remembered->writeOnce($map, fn() => SharedCoverage::write($shared, $this->project, $map));

        $canary = $this->patching->canary();

        return $command->with([
            GateVariable::SharedCoverage->value => $map,
            GateVariable::SuiteSeconds->value => sprintf('%F', SharedCoverage::seconds($shared)),
            ...($canary instanceof Group ? [GateVariable::Canary->value => $canary->name()] : []),
        ]);
    }

    /**
     * The shard's own map the plan handed over, where a patched shard opens
     * on the canary group, why it cannot, or none where the run opens on its
     * own suite.
     */
    private function shared(MutationRequest $request): CoverageMap|Unshared|CannotJudge
    {
        $handed = $request->coverage();

        if (! $this->patching->isOn() || ! $handed instanceof Handed || ! $request->judgedBy() instanceof WholeSuite) {
            return Unshared::Coverage;
        }

        $directory = $handed->own();

        $refusal = $this->refusal($request->withheld());

        $reading = fn(): CoverageMap|CannotJudge => SharedCoverage::in($this->project, $directory);

        return $refusal instanceof CannotJudge ? $refusal : $this->remembered->map($directory, $reading);
    }

    /** Why a shard cannot open on the canary group, if it cannot. */
    private function refusal(Withheld $withheld): Groups|CannotJudge
    {
        $vendor = $this->project->absolute($this->project->vendor());

        if (! $this->remembered->patched(static fn(): bool => Patch::isAppliedIn($vendor))) {
            return CannotJudge::because(sprintf(self::NOT_PATCHED, $this->project->vendor()->value()));
        }

        $groups = ($this->groups)($withheld);
        $canary = $this->patching->canary();

        return $groups instanceof CannotJudge || ! $canary instanceof Group || $groups->has($canary)
            ? $groups
            : CannotJudge::because(sprintf(self::EMPTY_CANARY, $canary->name()));
    }
}
