<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_values;
use function count;
use function min;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Unmade;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;

use function sprintf;

/**
 * The fake runner, as a test scripts it: what it answers when asked to mutate
 * and to run survivors again, and what it names itself; and every request,
 * retry and listing of its groups it was handed, in order.
 */
final class ScriptedRunner implements Runner
{
    /** @var list<MutationRequest> */
    private array $requests = [];

    /** @var list<array{Mutants, Seconds, WholeSuite|Group|Filter, Withheld, MutationRequest}> */
    private array $retries = [];

    /** @var list<array{Reproducible, MutationRequest, Seconds}> */
    private array $reproductions = [];

    /** @var list<Withheld> */
    private array $listings = [];

    /** @var list<Withheld> */
    private array $identified = [];

    /** @var list<array{Path, Withheld}> */
    private array $startedUp = [];

    /** @var non-empty-list<Seconds|CannotJudge> how long each run of no test takes, or why it cannot start; the last again for every run after */
    private array $startingUp;

    /**
     * @var list<MutationResult|CannotJudge> what each request to mutate is answered, the last again for every request
     *                                       after; with none, each is answered as `mutating` says
     */
    private array $turns = [];
    /** Which test files it says judge a file: as the fake does, or that it cannot say. */
    private CannotJudge|RunnerFake $judging;

    /** How it gives a mutant as an analyser checks it: as the fake does, or always so. */
    private Checkable|CannotJudge|RunnerFake $checking;

    /** What its unmutated controls find: as the fake's do, or always so. */
    private ControlRuns|CannotJudge|RunnerFake $controlling;

    /** @var list<array{Controls, MutationRequest}> every set of controls it was asked to run, in order */
    private array $controlled = [];

    private function __construct(
        private readonly RunnerFake $fake,
        private readonly Identity|CannotJudge $identity,
        private readonly CannotJudge|MutationResult|RunnerFake $mutating,
        private readonly CannotJudge|MutantStatus $retrying,
        private readonly CannotJudge|Groups $groups,
        private readonly CannotJudge|RunnerFake $covering,
        private readonly CannotJudge|Markers|RunnerFake $marking,
    ) {
        $this->startingUp = [$fake->startUp(Path::of('src/Money.php'), Withheld::nothing())];
        $this->checking = $fake;
        $this->judging = $fake;
        $this->controlling = $fake;
    }

    /** The fake runner over the fixture library, whose survivors survive again. */
    public static function fixture(): self
    {
        $fake = RunnerFake::ofTheFixture();
        $identity = $fake->identity(Withheld::nothing());

        $groups = $fake->groups(Withheld::nothing());

        return new self($fake, $identity, $fake, MutantStatus::Survived, $groups, $fake, $fake);
    }

    /** This runner, whose survivors are killed when run again, by a test or as the status says. */
    public function killingAgain(MutantStatus $as = MutantStatus::Killed): self
    {
        return new self(
            $this->fake,
            $this->identity,
            $this->mutating,
            $as,
            $this->groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, which cannot run a survivor again. */
    public function refusingAgain(string $why): self
    {
        return new self(
            $this->fake,
            $this->identity,
            $this->mutating,
            CannotJudge::because($why),
            $this->groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, which cannot mutate. */
    public function refusing(string $why): self
    {
        return new self(
            $this->fake,
            $this->identity,
            CannotJudge::because($why),
            $this->retrying,
            $this->groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, answering every request with these mutants, and this many skipped, whatever it asks. */
    public function answering(Mutants $mutants, int $skipped): self
    {
        return new self(
            $this->fake,
            $this->identity,
            MutationResult::of($mutants, $skipped),
            $this->retrying,
            $this->groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, naming itself so. */
    public function named(string $runner): self
    {
        $identity = Identity::of($runner, Versions::of(Version::of('fake/runner', '1.0.0', 'abc')), Digest::of('php'));

        return new self(
            $this->fake,
            $identity,
            $this->mutating,
            $this->retrying,
            $this->groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, which cannot say which test files judge a file. */
    public function refusingJudges(string $why): self
    {
        $scripted = clone $this;
        $scripted->judging = CannotJudge::because($why);

        return $scripted;
    }

    /** This runner, driving these packages at these versions. */
    public function driving(Version ...$versions): self
    {
        return new self(
            $this->fake,
            Identity::of('fake', Versions::of(...$versions), Digest::of('php')),
            $this->mutating,
            $this->retrying,
            $this->groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, behaving so. */
    public function behaving(RunnerBehaviour $behaviour): self
    {
        return new self(
            $this->fake->behaving($behaviour),
            $this->identity,
            $this->mutating,
            $this->retrying,
            $this->groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, whose runs of no test take this long, or cannot start, one after another, the last again after. */
    public function startingUpIn(Seconds|CannotJudge $first, Seconds|CannotJudge ...$then): self
    {
        $scripted = clone $this;
        $scripted->startingUp = [$first, ...array_values($then)];

        return $scripted;
    }

    /** This runner, answering its first request to mutate so, each after it the next of these, then the last again. */
    public function answeringInTurn(MutationResult|CannotJudge $first, MutationResult|CannotJudge ...$then): self
    {
        $scripted = clone $this;
        $scripted->turns = [$first, ...array_values($then)];

        return $scripted;
    }

    /** This runner, giving every mutant as an analyser checks it so, or unable to. */
    public function checking(Checkable|CannotJudge $answer): self
    {
        $scripted = clone $this;
        $scripted->checking = $answer;

        return $scripted;
    }

    /** This runner, its unmutated controls finding this, or unable to run. */
    public function controlling(ControlRuns|CannotJudge $answer): self
    {
        $scripted = clone $this;
        $scripted->controlling = $answer;

        return $scripted;
    }

    /** @return list<array{Controls, MutationRequest}> every set of controls it was asked to run, in order */
    public function controlled(): array
    {
        return $this->controlled;
    }

    /** This runner, behaving as Pest does without the gate's patch. */
    public function likePest(): self
    {
        return $this->behaving(RunnerBehaviour::standard()->holdingAsLoaded()->openingEachShard());
    }

    /** This runner, behaving as Pest does with the gate's patch, every shard opening on this canary group. */
    public function likePatchedPest(Group $canary): self
    {
        return $this->behaving(RunnerBehaviour::standard()->holdingAsLoaded()->readingInEveryKey($canary));
    }

    public function behaviour(): RunnerBehaviour
    {
        return $this->fake->behaviour();
    }

    /** This runner, which cannot name itself. */
    public function unnamed(string $why): self
    {
        return new self(
            $this->fake,
            CannotJudge::because($why),
            $this->mutating,
            $this->retrying,
            $this->groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, which cannot list the suite's groups. */
    public function unlisted(string $why): self
    {
        return new self(
            $this->fake,
            $this->identity,
            $this->mutating,
            $this->retrying,
            CannotJudge::because($why),
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, listing these groups of its suite. */
    public function listing(Groups $groups): self
    {
        return new self(
            $this->fake,
            $this->identity,
            $this->mutating,
            $this->retrying,
            $groups,
            $this->covering,
            $this->marking,
        );
    }

    /** This runner, which cannot measure the suite's coverage. */
    public function uncovering(string $why): self
    {
        return new self(
            $this->fake,
            $this->identity,
            $this->mutating,
            $this->retrying,
            $this->groups,
            CannotJudge::because($why),
            $this->marking,
        );
    }

    /** This runner, finding these of its own ignore markers in any files, or unable to look. */
    public function marking(Markers|CannotJudge $markers): self
    {
        return new self(
            $this->fake,
            $this->identity,
            $this->mutating,
            $this->retrying,
            $this->groups,
            $this->covering,
            $markers,
        );
    }

    /** @return list<MutationRequest> every request it was handed, in order */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return list<array{Mutants, Seconds, WholeSuite|Group|Filter, Withheld, MutationRequest}> every retry it was asked for */
    public function retries(): array
    {
        return $this->retries;
    }

    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        $this->identified[] = $withheld;

        return $this->identity;
    }

    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        $this->listings[] = $withheld;

        return $this->groups;
    }

    /** @return list<Withheld> what each listing of its groups withheld, in order */
    public function listings(): array
    {
        return $this->listings;
    }

    /** @return list<Withheld> what it was handed each time it was asked who it is, in order */
    public function identified(): array
    {
        return $this->identified;
    }

    public function coverage(CoverageRun|CoverageRead $request): CoverageMap|CannotJudge
    {
        return $this->covering instanceof CannotJudge ? $this->covering : $this->covering->coverage($request);
    }

    public function testsIn(Paths $files, CoverageMap $map): TestIds
    {
        return $this->fake->testsIn($files, $map);
    }

    public function judges(Path $file, CoverageMap $map): Paths|CannotJudge
    {
        return $this->judging instanceof CannotJudge ? $this->judging : $this->fake->judges($file, $map);
    }

    public function startUp(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        $this->startedUp[] = [$file, $withheld];

        return $this->startingUp[min(count($this->startedUp), count($this->startingUp)) - 1];
    }

    /**
     * The file and what was withheld of each run of no test it was asked for.
     *
     * @return list<array{Path, Withheld}>
     */
    public function startedUp(): array
    {
        return $this->startedUp;
    }

    public function mutate(
        MutationRequest $request,
        PreChecker $preChecker = new NoPreCheck(),
    ): MutationResult|CannotJudge {
        $this->requests[] = $request;

        if ($this->turns !== []) {
            return $this->turns[min(count($this->requests), count($this->turns)) - 1];
        }

        return $this->mutating instanceof RunnerFake ? $this->mutating->mutate($request) : $this->mutating;
    }

    public function controls(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $this->controlled[] = [$controls, $request];

        return $this->controlling instanceof RunnerFake
            ? $this->controlling->controls($request, $controls)
            : $this->controlling;
    }

    public function retry(MutationRequest $request, Mutants $mutants, Seconds $limit): Mutants|CannotJudge
    {
        $this->retries[] = [$mutants, $limit, $request->judgedBy(), $request->withheld(), $request];

        if ($this->retrying instanceof CannotJudge) {
            return $this->retrying;
        }

        $again = Mutants::none();

        foreach ($mutants as $mutant) {
            $again = $again->with(Mutant::of(
                $mutant->id(),
                $mutant->nativeId(),
                $mutant->location(),
                $mutant->mutation(),
                $this->retrying,
                $mutant->duration(),
            ));
        }

        return $again;
    }

    /** The fake's reproduction, its mutant given the status this runner answers a mutant run again with. */
    public function reproduce(
        Reproducible $mutant,
        MutationRequest $request,
        Seconds $limit,
    ): Reproduction|CannotJudge {
        $this->reproductions[] = [$mutant, $request, $limit];
        $again = $this->fake->reproduce($mutant, $request, $limit)->mutant();

        return $this->retrying instanceof CannotJudge ? $this->retrying : Reproduction::among(
            $mutant->id(),
            $again instanceof Mutant ? Mutants::of(Mutant::of(
                $again->id(),
                $again->nativeId(),
                $again->location(),
                $again->mutation(),
                $this->retrying,
                $again->duration(),
            )) : Mutants::none(),
            $again instanceof Unmade ? $again->why() : Reason::that('Made again.'),
            sprintf('scripted: %s run again', $mutant->id()->value()),
        );
    }

    /** @return list<array{Reproducible, MutationRequest, Seconds}> each mutant reproduced, as it was asked */
    public function reproductions(): array
    {
        return $this->reproductions;
    }

    public function checkable(Mutant $mutant): Checkable|CannotJudge
    {
        return $this->checking instanceof RunnerFake ? $this->checking->checkable($mutant) : $this->checking;
    }

    public function markers(Paths $files): Markers|CannotJudge
    {
        return $this->marking instanceof RunnerFake ? $this->marking->markers($files) : $this->marking;
    }

    public function definitions(): Paths
    {
        return $this->fake->definitions();
    }

    public function names(TestIds $tests, Withheld $withheld): TestNames
    {
        return $this->fake->names($tests, $withheld);
    }

    public function rootedAt(Path $package, Paths $tests): Runner|CannotJudge
    {
        return $this->fake->rootedAt($package, $tests);
    }
}
