<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_values;

use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Rechecking;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;

use function sprintf;

/** The last run's survivors re-checked, as the reports' tests show them. */
final readonly class Rechecks
{
    /** One still surviving, one killed now and one gone, in that order. */
    public static function mixed(): Rechecked
    {
        $survivor = Verdicts::survivor();

        return Rechecked::of(
            Uncovered::Count,
            Recheck::found($survivor, $survivor),
            Recheck::found(self::judged('src/Price.php:9', MutantJudgement::Survived), self::judged('src/Price.php:9', MutantJudgement::Killed)),
            Recheck::gone(self::judged('src/Cart.php:4', MutantJudgement::Survived)),
        );
    }

    /** A mutant judged this way, at this place. */
    public static function judged(string $at, MutantJudgement $judgement): JudgedMutant
    {
        return JudgedMutant::of(Verdicts::mutant($at, 'Plus', MutatorFamily::None, ''), $judgement);
    }

    /** A store with the default branch's ledger, and the feature branch's where it has these files. */
    public static function store(string ...$files): ProofStoreFake
    {
        $ledger = static function (string ...$proved): Ledger {
            $ledger = Ledger::empty();

            foreach ($proved as $file) {
                $ledger = $ledger->withProof(Proof::of(
                    Digest::sha256Of(sprintf('%s earlier', $file)),
                    Path::of($file),
                    Flows::mutantsOf($file),
                    Run::of('github:earlier', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
                ));
            }

            return $ledger;
        };
        $store = new ProofStoreFake();
        $store->write(Scope::branch('main'), $ledger('src/Money.php', 'src/Held.php'));

        if ($files !== []) {
            $store->write(Scope::branch('feature'), $ledger(...$files));
        }

        return $store;
    }

    /**
     * The ports of a run on this branch, the default branch being main.
     *
     * @return list<object>
     */
    public static function ports(string $branch, ProofStoreFake $store, object ...$more): array
    {
        return [$store, new CiPlanFake(RunOn::at(Scope::branch($branch), Scope::branch('main'))), ...array_values($more)];
    }

    /**
     * The plan these ports' run makes, as `plan` makes it in this mode, in one shard.
     *
     * @param list<object> $ports
     */
    public static function plan(array $ports, Settings $settings, Mode $mode): Plan|CannotJudge
    {
        $made = new Planning(Flows::adapters(Flows::project(), [], ...$ports), $settings, Flows::setup())
            ->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller);

        return $made instanceof PlanMade ? $made->plan() : $made;
    }

    /** What these adapters' run re-checks before the plan's shards; why there is no plan, where there is none. */
    public static function of(Adapters $adapters, Settings $settings, Plan|CannotJudge $plan): Rechecked|NoRecheck|CannotJudge
    {
        return $plan instanceof Plan ? new Rechecking($adapters, $settings, Flows::setup())->recheck($plan) : $plan;
    }

    /**
     * What the run on these ports re-checks of a plan of every unit, made in full.
     *
     * @param list<object> $ports
     */
    public static function in(array $ports, Settings $settings): Rechecked|NoRecheck|CannotJudge
    {
        return self::of(Flows::adapters(Flows::project(), [], ...$ports), $settings, self::plan($ports, $settings, Mode::full()));
    }
}
