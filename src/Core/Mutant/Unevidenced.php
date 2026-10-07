<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function count;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A kill needs evidence (ADR-0014, decision 17). A mutant a runner reports
 * killed, once the runner has run again what it doubts, stands where a test
 * is named as its killer, or where its process ended by a signal or by a
 * fatal error PHP recorded in it. Any other kill is unjudged, and its reason
 * says how its process ended: the code it exited with and the end of what it
 * printed, as the screen for secrets kept it (see Secrets), where the runner
 * gave them.
 */
final readonly class Unevidenced
{
    /** Why a kill no test is named for, and that neither a signal nor a fatal error ended, is unjudged. */
    private const string UNEVIDENCED
        = 'No test is named as its killer, and no signal or fatal error PHP recorded ended its process: %s.%s';

    private const string EXITED = 'it exited with code %d';

    private const string NO_CODE = 'the runner read no exit code';

    private const string TAIL = " Its output ended:\n%s";

    private const string NO_TAIL = ' None of its output is kept.';

    /** These mutants, each kill with no evidence unjudged, by the evidence of their kills. */
    public static function judged(Mutants $mutants, Evidences $evidence): Mutants
    {
        $judged = [];

        foreach ($mutants as $mutant) {
            $judged[] = self::stands($mutant, $evidence->of($mutant->id()))
                ? $mutant
                : $mutant->unjudged(Reason::that(self::reasonOf($evidence->of($mutant->id())->ended())));
        }

        return Mutants::of(...$judged);
    }

    /** Whether a mutant's result stands: any but a kill, a kill a test is named for, or one its ending vouches for. */
    private static function stands(Mutant $mutant, Evidence $evidence): bool
    {
        $ended = $evidence->ended();

        return $mutant->status() !== MutantStatus::Killed
            || count($mutant->killers()) > 0
            || ($ended instanceof Ended && ($ended->signalled() === true || $ended->fatal() === true));
    }

    /** Why a kill is unjudged, saying how its process ended, as far as the runner told. */
    private static function reasonOf(Ended|NotGiven $ended): string
    {
        $code = $ended instanceof Ended ? $ended->code() : NotGiven::value();
        $tail = $ended instanceof Ended ? $ended->tail() : NotGiven::value();

        return sprintf(
            self::UNEVIDENCED,
            $code instanceof NotGiven ? self::NO_CODE : sprintf(self::EXITED, $code),
            $tail instanceof NotGiven || $tail === '' ? self::NO_TAIL : sprintf(self::TAIL, $tail),
        );
    }
}
