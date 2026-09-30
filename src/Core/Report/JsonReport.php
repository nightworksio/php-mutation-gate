<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reason as Cause;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Counts;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;

/**
 * The gate's own report, `"format": 1`: everything in the verdict, as JSON a
 * program reads. `resources/report.schema.json` describes it, and it is
 * public API (ADR-0009, decision 2).
 */
final readonly class JsonReport
{
    /** The version of this format, which changes only when a reader would misread the new one. */
    public const int FORMAT = 1;

    private const float HUNDREDTHS = 100.0;

    public static function encode(Verdict $verdict): string
    {
        $overview = Overview::of($verdict);

        return Json::encode([
            'format' => self::FORMAT,
            'judgement' => $verdict->judgement()->value,
            'cutShort' => $verdict->wasCutShort(),
            'uncovered' => $overview->uncovered()->value,
            ...self::percent('score', $overview->score()),
            'counts' => self::counts($verdict->mutants()->counts()),
            'trees' => self::each($verdict->trees(), self::tree(...)),
            'newCode' => self::each($verdict->newCode(), self::newCode(...)),
            'mutants' => self::each($verdict->mutants(), self::mutant(...)),
            'reach' => self::texts($verdict->reach(), static fn(Cause $reason): string => $reason->text()),
            'warnings' => self::texts($verdict->warnings(), static fn(Warning $warning): string => $warning->text()),
            'failures' => self::texts($verdict->failures(), static fn(Failure $failure): string => $failure->text()),
        ]);
    }

    /** @return array<string, mixed> */
    private static function tree(TreeVerdict $tree): array
    {
        $declared = $tree->tree()->declared();
        $baseline = $tree->baseline();

        return [
            'path' => $tree->tree()->path()->value(),
            'package' => $tree->tree()->package()->path()->value(),
            ...$declared instanceof Floor ? ['declared' => $declared->percent()] : [],
            ...$declared instanceof Exempt ? ['exempt' => $declared->reason()] : [],
            ...$baseline instanceof Floor ? ['baseline' => $baseline->percent()] : [],
            ...self::percent('floor', $tree->floor()),
            ...self::percent('score', $tree->score()),
            ...self::percent('base', $tree->base()),
            ...self::percent('raised', $tree->raised()),
            'judgement' => $tree->judgement()->value,
            'counts' => self::counts($tree->counts()),
            'units' => self::each($tree->units(), self::unit(...)),
            'mutants' => self::ids($tree->mutants()),
        ];
    }

    /** @return array<string, mixed> */
    private static function newCode(NewCodeVerdict $set): array
    {
        return [
            'package' => $set->package()->path()->value(),
            'floor' => $set->floor()->percent(),
            ...self::percent('score', $set->score()),
            'judgement' => $set->judgement()->value,
            'counts' => self::counts($set->counts()),
            'mutants' => self::ids($set->mutants()),
        ];
    }

    /** @return array<string, mixed> */
    private static function unit(JudgedUnit $unit): array
    {
        $judgedBy = $unit->unit()->judgedBy();

        return [
            'path' => $unit->unit()->path()->value(),
            ...$judgedBy instanceof Group ? ['group' => $judgedBy->name()] : [],
            ...$judgedBy instanceof Filter ? ['filter' => $judgedBy->pattern()] : [],
            'origin' => $unit->origin()->value,
        ];
    }

    /** @return array<string, mixed> */
    private static function mutant(JudgedMutant $judged): array
    {
        $mutant = $judged->mutant();
        $end = $mutant->location()->end();
        $duration = $mutant->duration();
        $limit = $mutant->limit();
        $reason = $mutant->reason();
        $tests = [];

        foreach ($judged->tests() as $test) {
            $tests[] = $test->value();
        }

        return [
            'id' => $mutant->id()->value(),
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            ...$end instanceof Line ? ['end' => $end->number()] : [],
            'mutator' => $mutant->mutation()->mutator(),
            'family' => $mutant->mutation()->family()->value,
            'diff' => $mutant->mutation()->diff(),
            'status' => $mutant->status()->value,
            'judgement' => $judged->judgement()->value,
            ...$reason instanceof Reason ? ['reason' => $reason->text()] : [],
            'changedLine' => $judged->isOnChangedLine(),
            'tests' => $tests,
            'hint' => $judged->hint()->text(),
            'reproduce' => $judged->reproduce(),
            ...$duration instanceof Seconds ? ['seconds' => $duration->seconds()] : [],
            ...$limit instanceof Seconds ? ['limit' => $limit->seconds()] : [],
        ];
    }

    /** @return array<string, int> */
    private static function counts(Counts $counts): array
    {
        $numbers = [];

        foreach (MutantJudgement::cases() as $judgement) {
            $numbers[$judgement->value] = $counts->number($judgement);
        }

        return $numbers;
    }

    /** @return array<string, float> a percentage under a key, or nothing where there is none */
    private static function percent(string $key, object $value): array
    {
        return $value instanceof Score || $value instanceof Floor
            ? [$key => $value->hundredths() / self::HUNDREDTHS]
            : [];
    }

    /** @return list<string> */
    private static function ids(JudgedMutants $mutants): array
    {
        $ids = [];

        foreach ($mutants as $mutant) {
            $ids[] = $mutant->mutant()->id()->value();
        }

        return $ids;
    }

    /**
     * @template T
     *
     * @param  iterable<T>                        $items
     * @param  callable(T): array<string, mixed>  $encode
     * @return list<array<string, mixed>>
     */
    private static function each(iterable $items, callable $encode): array
    {
        $encoded = [];

        foreach ($items as $item) {
            $encoded[] = $encode($item);
        }

        return $encoded;
    }

    /**
     * @template T
     *
     * @param  iterable<T>           $items
     * @param  callable(T): string   $text
     * @return list<string>
     */
    private static function texts(iterable $items, callable $text): array
    {
        $texts = [];

        foreach ($items as $item) {
            $texts[] = $text($item);
        }

        return $texts;
    }
}
