<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Report\Mutator;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;

use function sprintf;

/**
 * What an alert says, in groups under a heading, for a chat to lay out: the
 * failing trees with floor, score and previous score, the failures no floor
 * decides and at most five survivors; why the run cannot judge; each tree
 * again after a recovery; or each lowered floor with its reason (ADR-0016,
 * decision 12). Whatever the project wrote is cut to 120 characters.
 */
final readonly class AlertLines
{
    /** The most survivors an alert names. */
    public const int SURVIVORS = 5;

    /** The longest text the project wrote an alert repeats, a path or a reason, before it is cut. */
    private const int LONGEST = 120;

    private const string BELOW = '%s: %s, below its floor of %s%s.';

    private const string AGAINST = '%s: %s against its floor of %s%s.';

    private const string WAS = '; it was %s';

    private const string LOWERED = '%s: its floor went from %s to %s. %s';

    private const string NO_REASON = 'The baseline gives no reason.';

    /**
     * Each group's heading and lines, leaving out a group with none.
     *
     * @return list<array{string, list<string>}>
     */
    public static function of(Alert $alert, Chat $chat): array
    {
        $verdict = $alert->verdict();
        $groups = match ($alert->event()) {
            AlertEvent::Failed => [
                ['Below the floor', self::trees($alert, $chat, Judgement::Failed)],
                ['Security below its floor', self::security($alert, $chat)],
                ['Failures', self::said($verdict->failures(), $chat)],
                ['Survivors', self::survivors($alert, $chat)],
            ],
            AlertEvent::CannotJudge => [['Why', self::said($verdict->obstacles(), $chat)]],
            AlertEvent::Recovered => [['Trees', self::trees($alert, $chat, Judgement::Passed)]],
            AlertEvent::FloorLowered => [['Floors lowered', self::lowered($alert, $chat)]],
        };
        $shown = [];

        foreach ($groups as [$heading, $lines]) {
            $shown = $lines === [] ? $shown : [...$shown, [$heading, $lines]];
        }

        return $shown;
    }

    /**
     * Each tree the verdict judged so, with its score, floor and previous score.
     *
     * @return list<string>
     */
    private static function trees(Alert $alert, Chat $chat, Judgement $judged): array
    {
        $lines = [];

        foreach ($alert->verdict()->trees() as $tree) {
            $lines = $tree->judgement() === $judged ? [...$lines, self::tree($alert, $tree, $chat)] : $lines;
        }

        return $lines;
    }

    /**
     * Each package's security set that failed, with its score and floor (ADR-0021, decision 21).
     *
     * @return list<string>
     */
    private static function security(Alert $alert, Chat $chat): array
    {
        $lines = [];

        foreach ($alert->verdict()->sets()->security() as $set) {
            $floor = $set->floor();
            $lines = $set->judgement() === Judgement::Failed
                ? [...$lines, sprintf(
                    self::BELOW,
                    $chat->text(Fit::line(SetText::securityName($set), self::LONGEST)),
                    Percent::of($set->score()),
                    $floor instanceof Floor ? Percent::of($floor) : '',
                    '',
                )]
                : $lines;
        }

        return $lines;
    }

    private static function tree(Alert $alert, TreeVerdict $tree, Chat $chat): string
    {
        $path = $tree->tree()->path();
        $floor = $tree->floor();
        $was = $alert->previous()->scoreOf($path);

        return sprintf(
            $tree->judgement() === Judgement::Failed ? self::BELOW : self::AGAINST,
            $chat->code(Fit::line($path->value(), self::LONGEST)),
            Percent::of($tree->score()),
            $floor instanceof Floor ? Percent::of($floor) : '',
            $was instanceof Score ? sprintf(self::WAS, Percent::of($was)) : '',
        );
    }

    /**
     * The first survivors in a set that failed, those on changed lines first.
     *
     * @return list<string>
     */
    private static function survivors(Alert $alert, Chat $chat): array
    {
        $overview = Overview::of($alert->verdict());
        $failing = [];

        foreach ($overview->survivors() as $judged) {
            $failing = $overview->isFailing($judged) ? [...$failing, $judged] : $failing;
        }

        $lines = [];

        foreach (array_slice($failing, 0, self::SURVIVORS) as $judged) {
            $lines[] = self::survivor($judged, $chat);
        }

        return count($failing) > self::SURVIVORS
            ? [...$lines, Fit::more(count($failing) - self::SURVIVORS)]
            : $lines;
    }

    private static function survivor(JudgedMutant $judged, Chat $chat): string
    {
        $mutant = $judged->mutant();
        $location = $mutant->location();

        return sprintf(
            '%s %s, %s',
            $chat->code(Fit::line(
                sprintf('%s:%d', $location->file()->value(), $location->start()->number()),
                self::LONGEST,
            )),
            $chat->text(Fit::line(Mutator::short($mutant->mutator()), self::LONGEST)),
            Label::of($judged->judgement()),
        );
    }

    /**
     * Each tree whose floor went down, with the reason its baseline gives.
     *
     * @return list<string>
     */
    private static function lowered(Alert $alert, Chat $chat): array
    {
        $lines = [];

        foreach ($alert->lowered() as $floor) {
            $lowering = $floor->lowering();
            $lines[] = sprintf(
                self::LOWERED,
                $chat->code(Fit::line($floor->tree()->value(), self::LONGEST)),
                Percent::of($floor->from()),
                Percent::of($floor->to()),
                $lowering instanceof Lowered
                    ? $chat->text(Fit::line($lowering->reason(), self::LONGEST))
                    : self::NO_REASON,
            );
        }

        return $lines;
    }

    /**
     * Each failure's or obstacle's sentence, cut to length.
     *
     * @param  iterable<Failure|CannotJudge> $items
     * @return list<string>
     */
    private static function said(iterable $items, Chat $chat): array
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[] = $chat->text(Fit::line($item instanceof Failure ? $item->text() : $item->why(), self::LONGEST));
        }

        return $lines;
    }
}
