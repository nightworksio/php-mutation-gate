<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\RecordedLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;

it('reads each file and line a covered test executed, in the order recorded', function (): void {
    $line = RecordedLine::of(StreamLine::covered(
        'MoneyTest::adds',
        ['/project/src/Money.php' => [11, 12], '/project/src/Shapes.php' => [3]],
        new TestEnded(passed: true, troubled: false, own: false, seconds: 0.5),
    ));

    expect($line->places())->toBe([['/project/src/Money.php', 11], ['/project/src/Money.php', 12], ['/project/src/Shapes.php', 3]])
        ->and([$line->covered, $line->test, $line->own, $line->seconds])->toBe([true, 'MoneyTest::adds', false, 0.5]);
});

it('reads no place from a line that holds no lines', function (): void {
    expect(RecordedLine::of('{"k":"covered","t":"MoneyTest::adds"}')->places())->toBe([]);
});
