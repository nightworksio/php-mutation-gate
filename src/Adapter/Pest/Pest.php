<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_values;
use function count;
use function dirname;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as ComposerInstalled;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Runner;

use function sprintf;

/**
 * Pest's own mutation testing, pestphp/pest-plugin-mutate, behind the Runner
 * port (ADR-0004). Pest runs in the project's root, and its results come from
 * this package's Pest plugin.
 */
final readonly class Pest implements Runner
{
    private const string RUNNER = 'pest';

    /** Where the gate runs Pest: the project's root, which the gate runs in. */
    private const string ROOT = '.';

    /** Where the adapter keeps what it writes. */
    private const string WORKSPACE = '.mutation-gate';

    /** The file Pest loads before any test, in the test directory it runs with, which the gate leaves at `tests`. */
    private const string BOOT_FILE = 'tests/Pest.php';

    private const string BY_GROUP_ALONE
        = 'Pest selects held tests by the holds: groups its plugin adds for #[Holds], not by the filter %s.';

    private const string COVERAGE_FAILED = "Pest's coverage run failed. Pest said:\n%s";

    private const string STALE_MAP = 'An earlier run left %s or its JUnit log, and the gate cannot remove them.';

    private const string COMMA = "Pest's --path and --ignore split on commas, so Pest cannot mutate %s less %s.";

    private const string NOT_PATCHED
        = 'pest.patch is on, but pest-plugin-mutate in %s is not patched. Run mutation-gate pest:patch.';

    private const string EMPTY_CANARY = 'pest.patch is on, but the canary group %s holds no test. Add one.';

    /** Where the map another job handed over is written again for this job's Pest, beside the results. */
    private const string SHARED_MAP = '%s/shared.coverage.php';

    private const string NOT_FOUND_AGAIN = 'Run again alone, Pest made no mutant with this id.';

    public function __construct(private Project $project, private Shell $shell, private Patching $patching)
    {
    }

    /**
     * Pest in the project the gate runs in, installed in this vendor
     * directory, as the `pest` runner's options configure it.
     */
    public static function fromOptions(Options $options, Path $vendor): self|Invalid
    {
        $read = PestOptions::read($options);

        if ($read instanceof Invalid) {
            return $read;
        }

        $project = Project::at(self::ROOT, $read->tests(), Path::of(self::WORKSPACE), $vendor);

        return new self($project, new ProcessShell($project->root()), $read->patching());
    }

    public function identity(): Identity|CannotJudge
    {
        $versions = Installed::versionsIn(
            $this->project->absolute(ComposerInstalled::fileIn($this->project->vendor())),
        );

        if ($versions instanceof CannotJudge) {
            return $versions;
        }

        return Identity::of(self::RUNNER, $versions, Platform::current()->digest());
    }

    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        return Listing::groupsIn(
            $this->shell->run(Invocation::installedIn($this->project->vendor())->listingGroups($withheld)),
        );
    }

    /**
     * A per-test line map: this job's own run of the suite under coverage, or
     * the map of the gate's own another job handed over, and never a
     * runner's map another job wrote.
     */
    public function coverage(CoverageRequest $request): CoverageMap|CannotJudge
    {
        if (! $request->runs()) {
            return SharedCoverage::in($this->project, $request->directory());
        }

        $directory = $this->project->directory($request->directory());
        $ran = $this->measured($request, $directory);
        $file = $ran instanceof CannotJudge ? $ran : CoverageFile::at(sprintf('%s/%s', $directory, Invocation::MAP));

        return $file instanceof CannotJudge ? $file : $file->map($this->project);
    }

    /**
     * Every test file whose class a covering test's `<Class>::` selects, or
     * every test file when the filter will not fit and the mutant runs against
     * the whole suite.
     */
    public function judges(Path $file, CoverageMap $map): Paths
    {
        $tests = [];

        foreach ($map->testsCoveringFile($file) as $test) {
            $tests[] = $test->value();
        }

        $selection = Selection::of($tests);
        $files = TestFiles::in($this->project);

        return $selection->fits() ? TestFiles::naming($files, $selection->classes()) : $files;
    }

    /** Every mutant of the requested files, where there are any to mutate: Pest's `--path` never names none. */
    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        if (count($request->files()) === 0) {
            return MutationResult::of(Mutants::none(), 0);
        }

        $results = $this->project->freshResults();

        return $results instanceof CannotJudge ? $results : $this->mutated($request, $results);
    }

    /**
     * The mutants run again, one run per file and mutator, judged by the tests
     * that judged their unit, and matched back by the gate's id. Pest allows
     * each mutant its own time, so no limit is laid on the run.
     */
    public function retry(
        Mutants $mutants,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
    ): Mutants|CannotJudge {
        $retried = Mutants::none();

        foreach ($this->batches($mutants) as $batch) {
            $request = MutationRequest::of(Paths::of($batch[0]->location()->file()), $judgedBy)
                ->onlyMutators(Mutators::named($batch[0]->mutation()->mutator()))
                ->withholding($withheld);
            $result = $this->mutate($request);

            if ($result instanceof CannotJudge) {
                return $result;
            }

            foreach ($batch as $mutant) {
                $retried = $retried->with($this->matching($mutant, $result->mutants()));
            }
        }

        return $retried;
    }

    /** Every `@pest-mutate-ignore` in the PHP files these paths name. */
    public function markers(Paths $files): Markers
    {
        return NativeMarkers::in($this->project, $files);
    }

    /** `tests/Pest.php`, and the PHPUnit config in the project's root, whichever name it has. */
    public function definitions(): Paths
    {
        return Paths::of(Path::of(self::BOOT_FILE), ...PhpUnitConfig::candidatesIn(Path::root()));
    }

    /**
     * The mutants by file and mutator, in the order each pair first appears.
     *
     * @return list<non-empty-list<Mutant>>
     */
    private function batches(Mutants $mutants): array
    {
        $batches = [];

        foreach ($mutants as $mutant) {
            $key = sprintf("%s\n%s", $mutant->location()->file()->value(), $mutant->mutation()->mutator());
            $batches[$key] = [...(array_key_exists($key, $batches) ? $batches[$key] : []), $mutant];
        }

        return array_values($batches);
    }

    private function mutated(MutationRequest $request, string $results): MutationResult|CannotJudge
    {
        $command = $this->commandFor($request, $results);

        return $command instanceof CannotJudge
            ? $command
            : new Interpretation($this->project, $this->patching)->of($this->shell->run($command), $results);
    }

    private function commandFor(MutationRequest $request, string $results): Command|CannotJudge
    {
        $judgedBy = $request->judgedBy();
        $files = PathList::of($request->files());
        $leftOut = PathList::of($request->leftOut());

        return match (true) {
            $judgedBy instanceof Filter => CannotJudge::because(sprintf(self::BY_GROUP_ALONE, $judgedBy->pattern())),
            $files->holdsAComma() || $leftOut->holdsAComma() => CannotJudge::because(
                sprintf(self::COMMA, $files->joined(', '), $leftOut->joined(', ')),
            ),
            default => $this->shared(
                $request,
                Invocation::installedIn($this->project->vendor())->mutation($request, $judgedBy, $results),
                $results,
            ),
        };
    }

    /** A clean coverage run into a directory, with no earlier run's map or log left there. */
    private function measured(CoverageRequest $request, string $directory): Ran|CannotJudge
    {
        $map = sprintf('%s/%s', $directory, Invocation::MAP);

        if (! $this->project->without($map, sprintf('%s/%s', $directory, Invocation::JUNIT))) {
            return CannotJudge::because(sprintf(self::STALE_MAP, $map));
        }

        $ran = $this->shell->run(Invocation::installedIn($this->project->vendor())->coverage($request, $directory));

        return $ran->succeeded() ? $ran : CannotJudge::because(sprintf(self::COVERAGE_FAILED, $ran->output()));
    }

    /**
     * The mutation command, reading the map another job handed over where
     * `pest:patch` lets a whole-suite run do so: the gate's own map, written
     * again beside the results as this job's Pest loads one.
     */
    private function shared(MutationRequest $request, Command $command, string $results): Command|CannotJudge
    {
        $directory = $request->coverage();

        if (! $this->patching->isOn() || ! $directory instanceof Path || ! $request->judgedBy() instanceof WholeSuite) {
            return $command;
        }

        $refusal = $this->refusal($request->withheld());
        $coverage = $refusal instanceof CannotJudge ? $refusal : SharedCoverage::in($this->project, $directory);

        if ($coverage instanceof CannotJudge) {
            return $coverage;
        }

        $map = sprintf(self::SHARED_MAP, dirname($results));
        SharedCoverage::write($coverage, $this->project, $map);

        return $command->with([
            Patch::COVERAGE => $map,
            Patch::SECONDS => sprintf('%F', SharedCoverage::seconds($coverage)),
            Patch::CANARY => $this->patching->canary()->name(),
        ]);
    }

    /** Why a shard cannot open on the canary group, if it cannot. */
    private function refusal(Withheld $withheld): Groups|CannotJudge
    {
        if (! Patch::isAppliedIn($this->project->absolute($this->project->vendor()))) {
            return CannotJudge::because(sprintf(self::NOT_PATCHED, $this->project->vendor()->value()));
        }

        $groups = $this->groups($withheld);

        return $groups instanceof CannotJudge || $groups->has($this->patching->canary())
            ? $groups
            : CannotJudge::because(sprintf(self::EMPTY_CANARY, $this->patching->canary()->name()));
    }

    private function matching(Mutant $mutant, Mutants $found): Mutant
    {
        foreach ($found as $again) {
            if ($again->id()->value() === $mutant->id()->value()) {
                return $again;
            }
        }

        return Interpretation::unjudged($mutant, Reason::that(self::NOT_FOUND_AGAIN));
    }
}
