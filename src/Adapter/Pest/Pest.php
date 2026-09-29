<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
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

    private const string MANIFEST = 'vendor/composer/installed.json';

    private const string VENDOR = 'vendor';

    private const string BY_GROUP_ALONE
        = 'Pest selects holding tests by group alone, so it cannot judge by the filter %s. Use a holds: group.';

    private const string COVERAGE_FAILED = "Pest's coverage run failed. Pest said:\n%s";

    private const string NOT_PATCHED
        = 'pest.patch is on, but pest-plugin-mutate is not patched. Run vendor/bin/mutation-gate pest:patch.';

    private const string EMPTY_CANARY = 'pest.patch is on, but the canary group %s holds no test. Add one.';

    private const string NOT_FOUND_AGAIN = 'Run again alone, Pest made no mutant with this id.';

    public function __construct(private Project $project, private Shell $shell, private Patching $patching)
    {
    }

    public function identity(): Identity|CannotJudge
    {
        $versions = Installed::versionsIn($this->project->absolute(Path::of(self::MANIFEST)));

        if ($versions instanceof CannotJudge) {
            return $versions;
        }

        return Identity::of(self::RUNNER, $versions, Platform::current()->digest());
    }

    public function groups(): Groups|CannotJudge
    {
        return Listing::groupsIn($this->shell->run(Invocation::listingGroups()));
    }

    public function coverage(CoverageRequest $request): CoverageMap|CannotJudge
    {
        $directory = $this->project->directory($request->directory());
        $ran = $request->runs()
            ? $this->shell->run(Invocation::coverage($request, $directory))
            : Ran::finished(succeeded: true, output: '');

        if (! $ran->succeeded()) {
            return CannotJudge::because(sprintf(self::COVERAGE_FAILED, $ran->output()));
        }

        $file = CoverageFile::at(sprintf('%s/%s', $directory, Invocation::MAP));

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

    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        $judgedBy = $request->judgedBy();

        if ($judgedBy instanceof Filter) {
            return CannotJudge::because(sprintf(self::BY_GROUP_ALONE, $judgedBy->pattern()));
        }

        $results = $this->project->freshResults();
        $command = $this->shared($request, Invocation::mutation($request, $judgedBy, $results));

        if ($command instanceof CannotJudge) {
            return $command;
        }

        return new Interpretation($this->project, $this->patching)->of($this->shell->run($command), $results);
    }

    /** Each mutant run again alone, narrowed to its file and mutator, matched back by the gate's id. */
    public function retry(Mutants $mutants, Seconds $limit): Mutants|CannotJudge
    {
        $retried = Mutants::none();

        foreach ($mutants as $mutant) {
            $request = MutationRequest::of(Paths::of($mutant->location()->file()), WholeSuite::tests())
                ->onlyMutators(Mutators::named($mutant->mutation()->mutator()))
                ->within($limit);
            $result = $this->mutate($request);

            if ($result instanceof CannotJudge) {
                return $result;
            }

            $retried = $retried->with(self::matching($mutant, $result->mutants()));
        }

        return $retried;
    }

    /** The mutation command, reading the map another job wrote where `pest:patch` lets a whole-suite run do so. */
    private function shared(MutationRequest $request, Command $command): Command|CannotJudge
    {
        $directory = $request->coverage();

        if (! $this->patching->isOn() || ! $directory instanceof Path || ! $request->judgedBy() instanceof WholeSuite) {
            return $command;
        }

        $refusal = $this->refusal();
        $map = sprintf('%s/%s', $this->project->absolute($directory), Invocation::MAP);
        $coverage = $refusal instanceof CannotJudge ? $refusal : CoverageFile::at($map);

        return $coverage instanceof CannotJudge ? $coverage : $command->with([
            Patch::COVERAGE => $map,
            Patch::SECONDS => sprintf('%F', $coverage->seconds()),
            Patch::CANARY => $this->patching->canary()->name(),
        ]);
    }

    /** Why a shard cannot open on the canary group, if it cannot. */
    private function refusal(): Groups|CannotJudge
    {
        if (! Patch::isAppliedIn($this->project->absolute(Path::of(self::VENDOR)))) {
            return CannotJudge::because(self::NOT_PATCHED);
        }

        $groups = $this->groups();

        return $groups instanceof CannotJudge || $groups->has($this->patching->canary())
            ? $groups
            : CannotJudge::because(sprintf(self::EMPTY_CANARY, $this->patching->canary()->name()));
    }

    private static function matching(Mutant $mutant, Mutants $found): Mutant
    {
        foreach ($found as $again) {
            if ($again->id()->value() === $mutant->id()->value()) {
                return $again;
            }
        }

        return Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            MutantStatus::Unjudged,
            $mutant->duration(),
        )->because(Reason::that(self::NOT_FOUND_AGAIN));
    }
}
