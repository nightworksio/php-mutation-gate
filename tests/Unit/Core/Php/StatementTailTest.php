<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\StatementTail;
use NightWorksIO\MutationGate\Tests\Support\Php;

const STATEMENT_TAIL_CODE = <<<'PHP'
    <?php
    namespace App;
    final class Band
    {
        public function of(int $n): string
        {
            $limit = 10;
            return match (true) {
                $n > $limit => match (true) {
                    $n > 100 => 'top',
                    default => 'high',
                },
                default => 'low',
            };
        }
        public function sum(array $items): int
        {
            if ($items === []) {
                return 0;
            } else {
                return array_sum(
                    $items,
                );
            }
        }
        private array $levels = [
            1,
            2,
        ];
    }
    $top = array_map(
        static fn(int $n): int => $n,
        [1],
    );
    if ($top !== []) {
        $kept = array_filter(
            $top,
        );
    }
    PHP;

/** The tail of the statement whose line holds the nth token written as this text. */
function statementTailAt(string $text, int $nth = 0): StatementTail|NotGiven
{
    $source = Php::source(STATEMENT_TAIL_CODE);

    return StatementTail::of($source, Php::indexOf($source, $text, $nth));
}

/** @return list<int> the tail's first and last line, or none where there is no tail */
function statementTailLines(StatementTail|NotGiven $tail): array
{
    return $tail instanceof StatementTail ? [$tail->first()->number(), $tail->last()->number()] : [];
}

it('spans the lines past a match (true) head that starts a statement, to where the match closes', function (): void {
    expect(statementTailLines(statementTailAt('true')))->toBe([9, 14])
        ->and(statementTailLines(statementTailAt('return', 0)))->toBe([9, 14])
        ->and(statementTailAt('true') instanceof StatementTail ? statementTailAt('true')->first() : null)->toEqual(Line::of(9));
});

it('spans the lines past a block head to where its block closes, and past a call spread over lines', function (): void {
    expect(statementTailLines(statementTailAt('if')))->toBe([19, 20])
        ->and(statementTailLines(statementTailAt('array_sum')))->toBe([22, 23]);
});

it('has no tail on a line that starts no statement: an arm, an else, or a value spread over lines', function (): void {
    expect(statementTailAt('100'))->toEqual(NotGiven::value())
        ->and(statementTailAt('>', 0))->toEqual(NotGiven::value())
        ->and(statementTailAt('else'))->toEqual(NotGiven::value())
        ->and(statementTailAt('static', 0))->toEqual(NotGiven::value());
});

it('has no tail on a statement of one line', function (): void {
    expect(statementTailAt('$limit', 0))->toEqual(NotGiven::value())
        ->and(statementTailAt('0'))->toEqual(NotGiven::value());
});

it('has no tail outside a function\'s body: a property\'s value, or code outside every function, in a block or not', function (): void {
    expect(statementTailAt('$levels'))->toEqual(NotGiven::value())
        ->and(statementTailAt('$top'))->toEqual(NotGiven::value())
        ->and(statementTailAt('$kept'))->toEqual(NotGiven::value());
});
