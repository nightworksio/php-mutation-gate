<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
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

/**
 * The fake runner, as a test scripts it: what it answers when asked to mutate
 * and to run survivors again, and what it names itself; and every request,
 * retry and listing of its groups it was handed, in order.
 */
final class ScriptedRunner implements Runner
{
    /** @var list<MutationRequest> */
    private array $requests = [];

    /** @var list<array{Mutants, Seconds, WholeSuite|Group|Filter, Withheld}> */
    private array $retries = [];

    /** @var list<Withheld> */
    private array $listings = [];

    /** @var list<Withheld> */
    private array $identified = [];

    private function __construct(
        private readonly RunnerFake $fake,
        private readonly Identity|CannotJudge $identity,
        private readonly CannotJudge|MutationResult|RunnerFake $mutating,
        private readonly CannotJudge|MutantStatus $retrying,
        private readonly CannotJudge|Groups $groups,
        private readonly CannotJudge|RunnerFake $covering,
        private readonly CannotJudge|Markers|RunnerFake $marking,
    ) {
    }

    /** The fake runner over the fixture library, whose survivors survive again. */
    public static function fixture(): self
    {
        $fake = RunnerFake::ofTheFixture();
        $identity = $fake->identity(Withheld::nothing());

        $groups = $fake->groups(Withheld::nothing());

        return new self($fake, $identity, $fake, MutantStatus::Survived, $groups, $fake, $fake);
    }

    /** This runner, whose survivors are killed when run again. */
    public function killingAgain(): self
    {
        return new self(
            $this->fake,
            $this->identity,
            $this->mutating,
            MutantStatus::Killed,
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

    /** This runner, behaving as Pest does without the gate's patch. */
    public function likePest(): self
    {
        return $this->behaving(RunnerBehaviour::standard()->holdingAsLoaded()->raisingNoLimit()->openingEachShard());
    }

    /** This runner, behaving as Pest does with the gate's patch, every shard opening on this canary group. */
    public function likePatchedPest(Group $canary): self
    {
        return $this->behaving(RunnerBehaviour::standard()->holdingAsLoaded()->raisingNoLimit()->readingInEveryKey($canary));
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

    /** @return list<array{Mutants, Seconds, WholeSuite|Group|Filter, Withheld}> every retry it was asked for */
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

    public function judges(Path $file, CoverageMap $map): Paths
    {
        return $this->fake->judges($file, $map);
    }

    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        $this->requests[] = $request;

        return $this->mutating instanceof RunnerFake ? $this->mutating->mutate($request) : $this->mutating;
    }

    public function retry(
        Mutants $mutants,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
    ): Mutants|CannotJudge {
        $this->retries[] = [$mutants, $limit, $judgedBy, $withheld];

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

    public function rootedAt(Path $package): Runner|CannotJudge
    {
        return $this->fake->rootedAt($package);
    }
}
