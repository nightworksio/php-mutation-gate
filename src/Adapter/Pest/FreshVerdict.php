<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function count;

use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\JUnitFailures;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;
use function trim;

/**
 * What a mutant's fresh Pest run says of it (ADR-0148), read from Pest's own
 * JUnit log, its exit status and the guard the gate's wrapper writes, and
 * never from the records of the gate's plugin, whose code may be the code
 * under mutation.
 *
 * - A run stopped at its limit timed out where the wrapper served the
 *   mutated file, and is unjudged where it never did.
 * - A run whose wrapper never served the mutated file is unjudged: its tests
 *   ran the original code.
 * - A run that failed on exactly the memory cap is out of memory.
 * - A covering test that failed or errored kills it, by those tests.
 * - A run that passed with a test that passed survived; one that passed with
 *   no test run, or every test skipped, is unjudged.
 * - A run that failed with no test case logged, as one that failed before
 *   any test does, or with only tests that do not cover it failing, is a kill
 *   no test is named for, which stands only once the same run with the file
 *   unmutated passes (ADR-0108).
 * - Any other run that failed is unjudged, with what Pest said.
 *
 * @internal
 */
final readonly class FreshVerdict
{
    private const string STOPPED_UNSERVED
        = 'Its fresh Pest run was stopped at the limit, and the mutated file never ran in its place.';

    private const string NEVER_SERVED
        = 'In its fresh Pest run the mutated file never ran in its place: the file was loaded some other way.';

    private const string NO_TEST_RAN = 'Its fresh Pest run passed with none of the tests that cover it run.';

    private const string SKIPPED = 'Its fresh Pest run skipped, or marked incomplete, every test that covers it.';

    private const string FAILED = "Its fresh Pest run failed, though no test failed. Pest said:\n%s";

    private const string FAILED_SILENTLY = 'Its fresh Pest run failed, though no test failed, and Pest said nothing.';

    private const string CONTROL_FAILS
        = 'Its fresh Pest run failed with no test named, and the same run with the file unmutated fails too.';

    private const string UNCONTROLLED
        = 'Its fresh Pest run failed with no test named, and no time was left for the same run unmutated.';

    private function __construct(
        private MutantStatus|Reason $verdict,
        private TestIds $killers,
        private bool $unnamed,
        private bool $vouched,
    ) {
    }

    /**
     * What a fresh run that ended so says of a mutant these tests cover, by
     * the guard it left, the JUnit log it wrote and this memory cap.
     */
    public static function of(Ran $ran, Guard $guard, string|false $log, TestIds $covering, MemoryCap $cap): self
    {
        $failed = JUnitFailures::of($log);
        $killers = $failed->among($covering);
        $cases = JUnitFailures::cases($log);

        return match (true) {
            $ran->wasStopped() && $guard->served() => self::judged(MutantStatus::TimedOut),
            $ran->wasStopped() => self::unjudged(Reason::that(self::STOPPED_UNSERVED)),
            ! $guard->served() => self::unjudged(Reason::that(self::NEVER_SERVED)),
            ! $ran->succeeded() && Exhaustion::isOf(Exhaustion::in($ran->output()), $cap)
                => self::judged(MutantStatus::OutOfMemory),
            count($killers) > 0 => new self(MutantStatus::Killed, $killers, unnamed: false, vouched: false),
            $ran->succeeded() && JUnitFailures::passed($log) > 0 => self::judged(MutantStatus::Survived),
            $ran->succeeded() => self::unjudged(Reason::that($cases === 0 ? self::NO_TEST_RAN : self::SKIPPED)),
            count($failed) > 0 || $cases === 0
                => new self(MutantStatus::Killed, TestIds::none(), unnamed: true, vouched: false),
            default => self::unjudged(self::said($ran)),
        };
    }

    /** Whether it is a kill no test is named for that no unmutated run has vouched for yet. */
    public function needsControl(): bool
    {
        return $this->unnamed && ! $this->vouched;
    }

    /**
     * This verdict, where it needs a control, as the same run with the file
     * unmutated ended: a kill once it passed, and unjudged where it failed
     * or never ran.
     */
    public function controlledBy(Ran|NotGiven $control): self
    {
        return match (true) {
            ! $this->needsControl() => $this,
            $control instanceof NotGiven => self::unjudged(Reason::that(self::UNCONTROLLED)),
            $control->succeeded() => new self(MutantStatus::Killed, TestIds::none(), unnamed: true, vouched: true),
            default => self::unjudged(Reason::that(self::CONTROL_FAILS)),
        };
    }

    /**
     * The mutant as this verdict judges it, its run having taken this long:
     * timed out at its limit, out of the memory cap, killed by its killers,
     * or unjudged, saying why.
     */
    public function mutant(Mutant $was, Seconds|Unmeasured $took, Seconds $limit, MemoryCap $cap): Mutant
    {
        $verdict = $this->needsControl() ? Reason::that(self::UNCONTROLLED) : $this->verdict;
        $mutant = Mutant::of(
            $was->id(),
            $was->nativeId(),
            $was->location(),
            $was->mutation(),
            $verdict instanceof Reason ? MutantStatus::Unjudged : $verdict,
            $took,
        );

        return match (true) {
            $verdict instanceof Reason => $mutant->because($verdict),
            $verdict === MutantStatus::TimedOut => $mutant->withLimit($limit),
            $verdict === MutantStatus::OutOfMemory => $mutant->withLimit($cap),
            $verdict === MutantStatus::Killed => $mutant->killedBy($this->killers),
            default => $mutant,
        };
    }

    /**
     * The evidence of its kill: how a run that killed it with no test named
     * ended, which its unmutated run vouched for; none of any other verdict.
     */
    public function evidence(Ran $ran): Evidence
    {
        return $this->unnamed && $this->vouched
            ? Evidence::none()->withEnded(Ended::ofRun($ran))->vouched()
            : Evidence::none();
    }

    private static function judged(MutantStatus $status): self
    {
        return new self($status, TestIds::none(), unnamed: false, vouched: false);
    }

    private static function unjudged(Reason $reason): self
    {
        return new self($reason, TestIds::none(), unnamed: false, vouched: false);
    }

    /** Why a run that failed with no test failing judged nothing, with what Pest said, where it said anything. */
    private static function said(Ran $ran): Reason
    {
        $output = trim($ran->output());

        return Reason::that($output === '' ? self::FAILED_SILENTLY : sprintf(self::FAILED, $output));
    }
}
