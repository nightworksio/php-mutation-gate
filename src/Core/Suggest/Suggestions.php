<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Suggest;

use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\Suggest;
use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\Cost\MutatorTally;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Score\Percentage;

use function sprintf;

/**
 * The changes `config:suggest` proposes, by three rules over a ledger
 * (ADR-0076), each with its evidence: of the runner the config chooses, an
 * idle mutator, whose mutants enough
 * were judged of, too few survived and which takes a large share of every
 * mutator's time, and a noisy one, most of whose mutants were ignored or
 * equivalent, are turned off by `mutators.except`, the costliest first; and
 * a slow directory gets a `paths` entry ({@see SlowDirectories}).
 *
 * @internal
 */
final readonly class Suggestions
{
    private const string IDLE
        = '`%s` under %s: %d judged, %d survived, %s of all the time (%s). It finds nothing: turn it off.';

    private const string NOISY
        = '`%s` under %s: %d of %d mutants (%s) ignored or equivalent. It makes noise: turn it off.';

    /** @return list<Suggestion> */
    public static function of(MutatorTallies $tallies, NewestProofs $proofs, Settings $settings): array
    {
        $thresholds = $settings->effective()->reports()->suggest();
        $tallies = $tallies->under($settings->runner()->name());
        $suggestions = [];

        foreach ($tallies->costliest() as $tally) {
            $evidence = self::idle($tally, $tallies, $thresholds);
            $evidence = $evidence instanceof NotGiven ? self::noisy($tally, $thresholds) : $evidence;
            $suggestions = $evidence instanceof NotGiven
                ? $suggestions
                : [...$suggestions, Suggestion::of($evidence, self::turnedOff($tally->mutator()))];
        }

        return [...$suggestions, ...SlowDirectories::in($proofs)->suggested($settings, $thresholds->timeoutCluster())];
    }

    private static function idle(MutatorTally $tally, MutatorTallies $tallies, Suggest $thresholds): string|NotGiven
    {
        $share = $tallies->shareOf($tally);
        $idle = $tally->judged() >= $thresholds->idleJudged()
            && $tally->survived() <= $thresholds->idleSurvivors()
            && $share instanceof Percentage
            && $share->hundredths() >= $thresholds->idleShare()->hundredths();

        return $idle ? sprintf(
            self::IDLE,
            $tally->mutator(),
            $tally->runner(),
            $tally->judged(),
            $tally->survived(),
            sprintf('%s%%', $share->written()),
            $tally->seconds()->text(),
        ) : NotGiven::value();
    }

    private static function noisy(MutatorTally $tally, Suggest $thresholds): string|NotGiven
    {
        $share = $tally->ignoredShare();
        $noisy = $tally->mutants() >= $thresholds->ignoredMutants()
            && $share instanceof Percentage
            && $share->hundredths() >= $thresholds->ignoredShare()->hundredths();

        return $noisy ? sprintf(
            self::NOISY,
            $tally->mutator(),
            $tally->runner(),
            $tally->ignored(),
            $tally->mutants(),
            sprintf('%s%%', $share->written()),
        ) : NotGiven::value();
    }

    private static function turnedOff(string $mutator): Layer
    {
        return Layer::of(Setup::of(mutators: Mutators::of(except: Listed::of($mutator))));
    }
}
