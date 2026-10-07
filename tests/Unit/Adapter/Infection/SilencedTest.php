<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Silenced;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\InfectionMutant;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads back each mutant a file records, by its file, line, mutator and diff', function (): void {
    $file = sprintf('%s/silenced.jsonl', Scratch::directory());
    file_put_contents($file, Silenced::line(InfectionMutant::of([], '/p/src/A.php', 3, 'one'), Seconds::of(8.0)));
    file_put_contents($file, Silenced::line(InfectionMutant::of([], '/p/src/B.php', 5, 'two'), Seconds::of(12.5)), FILE_APPEND);
    $silenced = Silenced::in($file);

    expect($silenced->of('/p/src/A.php', 3, 'Plus', 'one'))->toEqual(Seconds::of(8.0))
        ->and($silenced->of('/p/src/B.php', 5, 'Plus', 'two'))->toEqual(Seconds::of(12.5))
        ->and($silenced->of('/p/src/A.php', 3, 'Plus', 'two'))->toEqual(NotGiven::value());
});

it('records nothing where there is no file, or a line is not in shape', function (string $text): void {
    $file = sprintf('%s/silenced.jsonl', Scratch::directory());
    file_put_contents($file, $text);

    expect(Silenced::in($file)->of('/p/src/A.php', 3, 'Plus', 'one'))->toEqual(NotGiven::value())
        ->and(Silenced::in('/no/such/file')->of('/p/src/A.php', 3, 'Plus', 'one'))->toEqual(NotGiven::value());
})->with([
    'not JSON' => ['{'],
    'too few fields' => ["[\"/p/src/A.php\",3,\"Plus\",\"one\"]\n"],
]);
