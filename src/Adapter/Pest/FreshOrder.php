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
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * The order a fresh run asks Pest for (ADR-0202): under `tests.order:
 * killers-first` and `pest.freshOrder`, a mutant's likely killers first, as
 * the kill history ranks them, and the rest of its covering tests fastest
 * first by the map's times, written as the history its own run's seed is (see
 * Seed), stamped with the version the project's Pest names itself by (see
 * PestVersion); its control asks for the same order from a copy of its own.
 * None, so Pest keeps its own order, where the order is off, that version
 * cannot be read, or the history cannot be written.
 *
 * @internal
 */
final readonly class FreshOrder
{
    /** Where a mutant's order is written for its run, in the run's own directory. */
    private const string MUTATED = '%s/order/mutated/%s';

    /** Where it is written for its control. */
    private const string UNMUTATED = '%s/order/unmutated/%s';

    /** Why fresh runs keep Pest's own order where `pest.freshOrder` asks for the killers first. */
    private const string UNREAD = "Pest's own version is unread, so fresh runs keep its order: same verdicts, slower.";

    private function __construct(
        private Project $project,
        private MutantPlacement $placement,
        private KillHistory|NotGiven $history,
        private string $version,
        private TestDurations $times,
        private bool $unread = false,
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
        $wanted = $on && $ordering->putsKillersFirst();
        $version = $wanted ? PestVersion::of($project) : NotGiven::value();

        return new self(
            $project,
            $placement,
            is_string($version) ? $ordering->history() : NotGiven::value(),
            is_string($version) ? $version : '',
            $times,
            unread: $wanted && ! is_string($version),
        );
    }

    /** What the runs note of their order: that they keep Pest's own where Pest's own version is unread. */
    public function notes(): Warnings
    {
        return $this->unread ? Warnings::of(Warning::noting(self::UNREAD)) : Warnings::none();
    }

    /**
     * The options that ask the run of this mutant over these covering tests
     * for its order, written in the run's directory of this name; none where
     * it keeps Pest's own.
     *
     * @return list<string>
     */
    public function ofRun(Mutant $mutant, TestIds $covering, string $directory): array
    {
        return $this->options($mutant, $covering, sprintf(self::MUTATED, $directory, Seed::HISTORY));
    }

    /**
     * The options that ask its control for the same order, from a copy of its
     * own; none where it keeps Pest's own.
     *
     * @return list<string>
     */
    public function ofControl(Mutant $mutant, TestIds $covering, string $directory): array
    {
        return $this->options($mutant, $covering, sprintf(self::UNMUTATED, $directory, Seed::HISTORY));
    }

    /**
     * The options that ask a run for this mutant's order, written in the
     * placement's file of this name; none where it keeps Pest's own.
     *
     * @return list<string>
     */
    private function options(Mutant $mutant, TestIds $covering, string $name): array
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
        $written = $this->placement->written($name, $history);

        return $written instanceof CannotJudge ? [] : Reordering::reading(dirname($written));
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
