<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\Span;
use NightWorksIO\MutationGate\Core\Cluster\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Report\Columns;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$mutant = static fn(int $line, Line|Unreported $end, string $diff): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'M', $diff, 0),
    '1',
    Location::of(Path::of('src/Money.php'), Line::of($line), $end),
    Mutation::of('M', MutatorFamily::None, $diff),
    MutantStatus::Survived,
    Unmeasured::duration(),
);

it('places a mutant at the tokens its diff changed, the end past the last', function () use ($mutant): void {
    expect(Columns::in(Contents::of(Verdicts::MONEY))->of($mutant(7, Line::of(7), Verdicts::BOUNDARY)))->toBe([
        'start' => ['line' => 7, 'column' => 21],
        'end' => ['line' => 7, 'column' => 22],
    ]);
});

it('places a removed statement from its first token to its last', function () use ($mutant): void {
    expect(Columns::in(Contents::of(Verdicts::MONEY))->of($mutant(8, Unreported::line(), Verdicts::diff('return true;', ''))))->toBe([
        'start' => ['line' => 8, 'column' => 13],
        'end' => ['line' => 8, 'column' => 25],
    ]);
});

it('places every mutant of a file from one reading of it', function () use ($mutant): void {
    $columns = Columns::in(Contents::of(Verdicts::MONEY));

    expect($columns->of($mutant(7, Line::of(7), Verdicts::BOUNDARY))['start'])->toBe(['line' => 7, 'column' => 21])
        ->and($columns->of($mutant(11, Line::of(11), Verdicts::diff('return false;', 'return true;')))['start'])->toBe(['line' => 11, 'column' => 16]);
});

it('counts characters, not bytes', function () use ($mutant): void {
    $source = "<?php\n\$naïve = 'é' . \$a + \$b;\n";
    $diff = "@@ @@\n-\$naïve = 'é' . \$a + \$b;\n+\$naïve = 'é' . \$a - \$b;\n";

    expect(Columns::in(Contents::of($source))->of($mutant(2, Line::of(2), $diff)))->toBe([
        'start' => ['line' => 2, 'column' => 19],
        'end' => ['line' => 2, 'column' => 20],
    ]);
});

it('spans a mutant it cannot place from the first column of its lines to past the end', function (string $diff, int $line, Line|Unreported $end, array $to) use ($mutant): void {
    expect(Columns::in(Contents::of(Verdicts::MONEY))->of($mutant($line, $end, $diff)))->toBe([
        'start' => ['line' => $line, 'column' => 1],
        'end' => $to,
    ]);
})->with([
    'tokens that are not on its line' => [Verdicts::diff('return 42;', 'return 43;'), 8, Line::of(8), ['line' => 8, 'column' => 25]],
    'a change that only adds' => [Verdicts::diff('return true;', 'return ! true;'), 8, Line::of(9), ['line' => 9, 'column' => 10]],
    'no diff' => ['', 3, Unreported::line(), ['line' => 3, 'column' => 18]],
    'a line past the end of the file' => ['', 40, Unreported::line(), ['line' => 40, 'column' => 1]],
]);

it('spans the tokens a mutant changed by where they stand among the file\'s', function (string $diff, int $line, Line|Unreported $end, int $first, int $last) use ($mutant): void {
    $span = Columns::in(Contents::of(Verdicts::MONEY))->span($mutant($line, $end, $diff));

    expect($span)->toBeInstanceOf(Span::class)
        ->and($span instanceof Span ? [$span->first(), $span->last()] : [])->toBe([$first, $last]);
})->with([
    // From 0: `final class Money { public function fits ( int $amount , int $limit ) : bool { if ( $amount < $limit ) { return true ;`
    'one token' => [Verdicts::BOUNDARY, 7, Line::of(7), 20, 20],
    'an expression' => [Verdicts::diff('if ($amount < $limit) {', 'if (! ($amount < $limit)) {'), 7, Line::of(7), 19, 21],
    'a statement, up to the semicolon that ends it' => [Verdicts::diff('return true;', ''), 8, Unreported::line(), 24, 26],
]);

it('places no mutant whose change runs past one statement, or cannot be found', function (string $diff, int $line, Line|Unreported $end) use ($mutant): void {
    expect(Columns::in(Contents::of(Verdicts::MONEY))->span($mutant($line, $end, $diff)))->toEqual(Unplaced::mutant());
})->with([
    'a block' => ["@@ @@\n-        if (\$amount < \$limit) {\n-            return true;\n-        }\n", 7, Line::of(9)],
    'two statements' => ["@@ @@\n-            return true;\n-        }\n", 8, Line::of(9)],
    'tokens that are not on its line' => [Verdicts::diff('return 42;', 'return 43;'), 8, Line::of(8)],
    'a change that only adds' => [Verdicts::diff('return true;', 'return ! true;'), 8, Line::of(8)],
]);

it('places no mutant whose change holds a semicolon, an opening brace or a closing brace before its end', function (string $removed, int $line) use ($mutant): void {
    $source = Contents::of("<?php\n\$a = 1; \$b = 2;\nif (\$ok) {\n    run(function () {\n    });\n}\n");

    expect(Columns::in($source)->span($mutant($line, Line::of($line), sprintf("@@ @@\n-%s\n", $removed))))->toEqual(Unplaced::mutant());
})->with([
    'two statements on a line' => ['$a = 1; $b = 2;', 2],
    'a block opened' => ['if ($ok) {', 3],
    'a closure closed' => ['    });', 5],
]);
