<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;

use Closure;

use function count;
use function explode;
use function implode;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\KillControl;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The kills of a run, as far as each own run's records can vouch for them,
 * whether it loaded only the test files its covering tests need or every one.
 * A kill is doubtful where no test is named as the killer, or where the
 * records cannot be read. Any other kill stands only where the replay of its
 * run vouches for it, where its order is known, unless `flaky.killControls:
 * alone` leaves a run that loaded every file to its kill control (see
 * PrefixReplay), or else where its control passes: the tests of the files it
 * loaded, loaded alone, with the file it changes served unmutated through
 * Pest's override as the mutant's copy was (see Control). A kill only tests
 * that errored made is vouched for the same way, but one its replay does not
 * let stand is doubted and runs again with every test file, where a kill a
 * failed assertion made is left unjudged (ADR-0036). A file left out can hold
 * what a test needs without a name, such as a hook `pest()->in()` registers,
 * state its loading sets or a helper a test calls by a name built at run
 * time, and a test can fail only while the override serves a file; either
 * would pass as a killer, and either fails the replay and the control too.
 * Mutants that share Pest's id share their own run, and so its doubt. A
 * kill of a mutant Pest left uncovered is the trial's (ADR-0004),
 * which ran no own process of Pest's and first ran its tests alone on the
 * unmutated code, served as its mutant is, so it is never doubted.
 *
 * @internal
 */
final readonly class NarrowedKills
{
    /** Where the file a narrowed kill changes and the files its run loaded are joined into the key it is kept by. */
    private const string BETWEEN = "\n";

    /**
     * @param list<Mutant>                $doubtful
     * @param array<string, list<Mutant>> $narrowed the kills of narrowed own runs whose order is not known, by the
     *                                              file each changes and the files its run loaded, joined
     * @param array<string, PrefixReplay> $replays  the replay of each narrowed own run whose order is known, by the
     *                                              file its mutant changes and the key of its order, joined
     * @param array<string, list<Mutant>> $replayed the kills a failed assertion made that each replay vouches
     *                                              for, by the same key
     * @param array<string, list<Mutant>> $errored  the kills only tests that errored made that each replay vouches
     *                                              for, by the same key
     * @param array<string, Prefix>       $planned  the whole order the run of each replay was to take, where known,
     *                                              by the same key
     */
    private function __construct(
        private array $doubtful,
        private array $narrowed,
        private array $replays = [],
        private array $replayed = [],
        private array $errored = [],
        private array $planned = [],
    ) {
    }

    public static function in(
        MutationResult $result,
        string $results,
        Project $project,
        KillControl $control = KillControl::Order,
    ): self {
        $recorded = RecordedKills::of(Records::in($results));
        $doubtful = [];
        $narrowed = [];
        $replaying = [];

        foreach ($recorded->judged($result->mutants()) as $mutant) {
            $vouched = $recorded->vouchedFor($mutant);

            if (! is_array($vouched)) {
                $doubtful[] = $mutant;

                continue;
            }

            [$run, $copy, $limit] = $vouched;
            $replay = PrefixReplay::ofRun($mutant, $run, $copy, $limit, $project, $control);

            if ($replay instanceof PrefixReplay) {
                $planned = $run->planned(Paths::of(...array_map($project->relative(...), $run->narrowedTo())));
                $replaying[] = [$mutant, $replay, $limit, $run->killedByErrorsOnly(), $planned];

                continue;
            }

            if ($run->narrowedTo() !== []) {
                $set = implode(self::BETWEEN, [$mutant->location()->file()->value(), ...$run->narrowedTo()]);
                $narrowed[$set][] = $mutant;
            }
        }

        [$replays, $replayed, $errored, $planned] = self::byOrder($replaying);

        return new self($doubtful, $narrowed, $replays, $replayed, $errored, $planned);
    }

    /**
     * How many controls the narrowed kills ask for: one for each file changed
     * and set of files loaded, and one replay for each file changed and order.
     */
    public function sets(): int
    {
        return count($this->narrowed) + count($this->replays);
    }

    /**
     * What the replays leave of the narrowed kills whose order is known: each
     * kill a failed assertion made whose replay does not let it stand,
     * unjudged with why (see ReplayVerdict), and each kill only tests that
     * errored made whose replay does not let it stand, doubted. All the
     * replays are asked at once, so they can run side by side.
     *
     * @param Closure(non-empty-list<PrefixReplay>): list<ReplayVerdict> $replay what each replay says, in order
     */
    public function unvouched(Closure $replay): ReplayedKills
    {
        $said = WholeOrders::of($this->replays, $this->planned)->said($replay);
        $unjudged = [];
        $doubted = [];

        foreach (array_keys($this->replays) as $key) {
            $verdict = array_key_exists($key, $said) ? $said[$key] : ReplayVerdict::NoTime;
            $reason = $verdict->reason();

            if ($reason instanceof NotGiven) {
                continue;
            }

            $unjudged = [...$unjudged, ...array_map(
                static fn(Mutant $mutant): Mutant => Interpretation::unjudged($mutant, $reason),
                $this->replayed[$key],
            )];
            $doubted = [...$doubted, ...$this->errored[$key]];
        }

        return ReplayedKills::of(Mutants::of(...$unjudged), Mutants::of(...$doubted));
    }

    /**
     * The doubtful kills, and each narrowed kill whose control fails: the
     * tests of the files its run loaded, judged as the run was, with the file
     * it changes served unmutated, all asked at once, so the runs that answer
     * can run side by side.
     *
     * @param Closure(non-empty-list<Control>): list<bool> $pass     whether each control passes, in the order given
     * @param ReplayedKills                                $replayed the kills only tests that errored made that their
     *                                                               replays doubt
     */
    public function doubted(WholeSuite|Group|Filter $judgedBy, Closure $pass, ReplayedKills $replayed): Mutants
    {
        $doubted = [...$this->doubtful, ...$replayed->doubted()];
        $keys = array_keys($this->narrowed);
        $passed = $keys === [] ? [] : $pass(array_map(
            static function (string $key) use ($judgedBy): Control {
                $parts = explode(self::BETWEEN, $key);

                return Control::of(
                    Path::of($parts[0]),
                    Paths::of(...array_map(Path::of(...), array_slice($parts, 1))),
                    $judgedBy,
                );
            },
            $keys,
        ));

        foreach ($keys as $at => $key) {
            $stands = array_key_exists($at, $passed) && $passed[$at];
            $doubted = $stands ? $doubted : [...$doubted, ...$this->narrowed[$key]];
        }

        return Mutants::of(...$doubted);
    }

    /**
     * The replay each kill's narrowed own run asks for, by the file its
     * mutant changes and the key of its order, joined, and the kills each
     * vouches for, by the same key: those a failed assertion made, and those
     * only tests that errored made.
     *
     * @param list<array{Mutant, PrefixReplay, Seconds|Unmeasured, bool, Prefix|NotGiven}> $replaying each kill, its
     *                                                                         replay, its limit, whether only errors
     *                                                                         made it, and the whole order its run
     *                                                                         was to take
     *
     * @return array{
     *     array<string, PrefixReplay>,
     *     array<string, list<Mutant>>,
     *     array<string, list<Mutant>>,
     *     array<string, Prefix>,
     * }
     */
    private static function byOrder(array $replaying): array
    {
        $replays = [];
        $replayed = [];
        $errored = [];
        $planned = [];

        foreach ($replaying as [$mutant, $replay, $limit, $errorsOnly, $whole]) {
            $key = implode(self::BETWEEN, [$mutant->location()->file()->value(), $replay->key()]);

            $replays[$key] = array_key_exists($key, $replays) ? $replays[$key]->alsoFor($limit) : $replay;
            $planned += $whole instanceof Prefix && is_string($whole->key()) ? [$key => $whole] : [];
            $replayed += [$key => []];
            $errored += [$key => []];

            if ($errorsOnly) {
                $errored[$key][] = $mutant;

                continue;
            }

            $replayed[$key][] = $mutant;
        }

        return [$replays, $replayed, $errored, $planned];
    }
}
