<?php

declare(strict_types=1);

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
