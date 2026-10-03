<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

use function sprintf;

/**
 * Whether a test is what a mutant asks for (ADR-0015, decision 1): a
 * survivor and an uncovered mutant are stubbed. A flaky, unjudged or too
 * slow or heavy mutant gets the next step instead, and a killed, ignored
 * or proven equivalent one has nothing to stub.
 */
final readonly class Unstubbable
{
    private const string NOTHING = 'Mutant %s is %s: nothing to stub. %s';

    private const string FLAKY = <<<'SAID'
        Mutant %s is flaky, so a test written for it now may pass by chance. %s Run mutation-gate triage %s to see.
        SAID;

    private const string UNMEASURABLE = 'Mutant %s is %s, so no test can be seen to kill it yet. %s';

    private const string UNREACHED = <<<'SAID'
        No test reaches the value mutant %s changes. Write a test that references it, and run mutation-gate to judge it.
        SAID;

    private const string UNJUDGED = 'Mutant %s is unjudged. %s Run mutation-gate to judge it first.';

    /** The mutant, where a test is what it asks for; or why not, and what to do instead. */
    public static function checked(JudgedMutant|JudgedKill $judged): JudgedMutant|CannotJudge
    {
        if ($judged instanceof JudgedMutant && $judged->judgement()->asksForATest()) {
            return $judged;
        }

        $id = $judged->mutant()->id()->value();
        $hint = $judged->hint()->text();
        $reason = $judged->mutant()->reason();

        return CannotJudge::because(match ($judged->judgement()) {
            MutantJudgement::Flaky => sprintf(self::FLAKY, $id, $hint, $judged->mutant()->location()->file()->value()),
            MutantJudgement::TooSlowToJudge, MutantJudgement::TooHeavyToJudge
                => sprintf(self::UNMEASURABLE, $id, Label::of($judged->judgement()), $hint),
            MutantJudgement::Unjudged => $reason instanceof Reason && $reason->isUnreached()
                ? sprintf(self::UNREACHED, $id)
                : sprintf(self::UNJUDGED, $id, $hint),
            MutantJudgement::Survived,
            MutantJudgement::Uncovered,
            MutantJudgement::Killed,
            MutantJudgement::KilledByStaticAnalysis,
            MutantJudgement::Errored,
            MutantJudgement::KilledByTimeout,
            MutantJudgement::KilledByMemoryCap,
            MutantJudgement::Ignored,
            MutantJudgement::IgnoredByMarker,
            MutantJudgement::Equivalent => sprintf(self::NOTHING, $id, Label::of($judged->judgement()), $hint),
        });
    }
}
