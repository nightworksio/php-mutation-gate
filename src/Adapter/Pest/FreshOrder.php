<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function dirname;
use function file_get_contents;
use function is_readable;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Order\Reordering;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as ComposerInstalled;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Coverage\TestDurations;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Php\Functions;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * The order a fresh run asks Pest for (ADR-0202): under `tests.order:
 * killers-first` and `pest.freshOrder`, a mutant's likely killers first, as
 * the kill history ranks them, and the rest of its covering tests fastest
 * first by the map's times, written as the history its own run's seed is (see
 * Seed); its control asks for the same order from a copy of its own. None,
 * so Pest keeps its own order, where the order is off, Pest's version cannot
 * be read, or the history cannot be written.
 *
 * @internal
 */
final readonly class FreshOrder
{
    /** Where a mutant's order is written for its run, in its own directory. */
    private const string MUTATED = '%s/order/mutated/%s';

    /** Where it is written for its control. */
    private const string UNMUTATED = '%s/order/unmutated/%s';

    private function __construct(
        private Project $project,
        private MutantPlacement $placement,
        private KillHistory|NotGiven $history,
        private string $version,
        private TestDurations $times,
    ) {
    }

    /** The order of this request's fresh runs, timed by these durations, where `pest.freshOrder` is on. */
    public static function of(
        Project $project,
        MutantPlacement $placement,
        MutationRequest $request,
        TestDurations $times,
        bool $on,
    ): self {
        $ordering = $request->search()->ordering();
        $version = self::pestIn($project);
        $ordered = $on && $ordering->putsKillersFirst() && is_string($version);

        return new self(
            $project,
            $placement,
            $ordered ? $ordering->history() : NotGiven::value(),
            is_string($version) ? $version : '',
            $times,
        );
    }

    /**
     * The options that ask the run of this mutant over these covering tests
     * for its order; none where it keeps Pest's own.
     *
     * @return list<string>
     */
    public function ofRun(Mutant $mutant, TestIds $covering): array
    {
        return $this->options($mutant, $covering, self::MUTATED);
    }

    /**
     * The options that ask its control for the same order, from a copy of its
     * own; none where it keeps Pest's own.
     *
     * @return list<string>
     */
    public function ofControl(Mutant $mutant, TestIds $covering): array
    {
        return $this->options($mutant, $covering, self::UNMUTATED);
    }

    /**
     * The options that ask a run for this mutant's order, written where this
     * says; none where it keeps Pest's own.
     *
     * @return list<string>
     */
    private function options(Mutant $mutant, TestIds $covering, string $where): array
    {
        if (! $this->history instanceof KillHistory) {
            return [];
        }

        $history = Seed::history(
            $this->version,
            $this->history->likelyKillers($mutant->id(), $this->enclosing($mutant)),
            $covering,
            $this->secondsOf(...),
        );
        $name = sprintf($where, $mutant->id()->value(), Seed::HISTORY);
        $written = $this->placement->written($name, $history);

        return $written instanceof CannotJudge ? [] : Reordering::reading(dirname($written));
    }

    /** The release of Pest the project installed, as Pest names its own; none where it cannot be read. */
    private static function pestIn(Project $project): string|NotGiven
    {
        $versions = Installed::versionsIn($project->absolute(ComposerInstalled::fileIn($project->vendor())));

        if ($versions instanceof CannotJudge) {
            return NotGiven::value();
        }

        foreach ($versions as $version) {
            if ($version->package() === Package::Pest->value && $version->isRelease()) {
                return $version->release();
            }
        }

        return NotGiven::value();
    }

    /** The function the mutant is in, as the history ranks its killers by; nameless where its file cannot be read. */
    private function enclosing(Mutant $mutant): Enclosing|Nameless
    {
        $file = $mutant->location()->file();
        $absolute = $this->project->absolute($file);
        $text = is_readable($absolute) ? file_get_contents($absolute) : false;

        return is_string($text)
            ? Enclosing::of($file, Functions::in(Contents::of($text))->around($mutant->location()->start()))
            : Nameless::code();
    }

    private function secondsOf(TestId $test): float
    {
        $seconds = $this->times->of($test);

        return $seconds instanceof Seconds ? $seconds->seconds() : 0.0;
    }
}
