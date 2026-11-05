<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function count;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpan;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;

/**
 * The functions of a proof's unit that hold its mutants, as a proof's
 * optional `functions` field holds them (ADR-0084): each as
 * `[name, first line, last line]`, so a mutant of the base is scored with
 * its function by its line.
 *
 * Reading keeps each well-formed span and drops anything else, never
 * repairing it; a proof without the field names no function, which loses a
 * function's change against the base, never a verdict, so the ledger's
 * format does not count it.
 *
 * @internal the shape of a proof's `functions` field in the ledger file
 */
final readonly class FunctionsRecord
{
    public const string FIELD = 'functions';

    /** How many items a span holds: its name, its first line and its last line. */
    private const int SPAN = 3;

    /** @return array{functions?: list<array{string, int, int}>} the field, where the proof names a function */
    public static function of(FunctionSpans $spans): array
    {
        $written = [];

        foreach ($spans as $span) {
            $written[] = [$span->name(), $span->first()->number(), $span->last()->number()];
        }

        return $written === [] ? [] : [self::FIELD => $written];
    }

    /** What a proof's `functions` field holds; nothing where it holds none. */
    public static function read(Node $field): FunctionSpans
    {
        $spans = [];

        foreach ($field->isPresent() ? self::itemsOf($field) : [] as $item) {
            $spans = [...$spans, ...self::spanIn($item)];
        }

        return FunctionSpans::of(...$spans);
    }

    /** @return list<FunctionSpan> the span an item holds, or none where it is malformed */
    private static function spanIn(Node $item): array
    {
        try {
            $fields = $item->items();

            if (count($fields) !== self::SPAN) {
                return [];
            }

            [$name, $first, $last] = $fields;
            $span = FunctionSpan::of($name->text(), Line::of($first->integer()), Line::of($last->integer()));
        } catch (NotInShape) {
            return [];
        }

        return [$span];
    }

    /** @return list<Node> */
    private static function itemsOf(Node $field): array
    {
        try {
            return $field->items();
        } catch (NotInShape) {
            return [];
        }
    }
}
