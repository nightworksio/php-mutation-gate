<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ServedClasses;
use NightWorksIO\MutationGate\Adapter\Pest\Split\LineRecorder;
use NightWorksIO\MutationGate\Adapter\Pest\Split\PcovLines;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Recorded;
use NightWorksIO\MutationGate\Tests\Fakes\LineCollectorFake;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedBox;

afterEach(function (): void {
    Scratch::sweep();
});

it('records the lines of a copy this process serves as its original\'s, joined to the original\'s own, and every other file\'s as they are', function (): void {
    $box = ServedBox::registered();

    try {
        ServedClasses::serve($box->file, $box->copy);
        $original = (string) realpath($box->file);
        $served = (string) realpath(sprintf('%s.served.php', $box->copy));

        expect(Recorded::atOriginals([$served => [9, 3], $original => [3, 5], '/project/src/Other.php' => [7]]))->toBe([
            $original => [3, 5, 9],
            '/project/src/Other.php' => [7],
        ])
            ->and(Recorded::atOriginals([$served => [4, 2]]))->toBe([$original => [2, 4]])
            ->and(Recorded::atOriginals([]))->toBe([]);
    } finally {
        ServedClasses::forget($box->file);
        $box->loader->unregister();
    }
});

it('starts a test\'s lines afresh through its collector, and collects the lines it executed and every line of each file not known yet', function (): void {
    $collector = new LineCollectorFake([[
        '/project/src/Money.php' => [12 => 1, 11 => 1, 14 => -1],
        '/project/src/Known.php' => [3 => 1, 4 => -1],
        '/project/src/Unrun.php' => [8 => -1, 7 => -1],
    ]]);
    $recorded = new Recorded($collector);

    expect($recorded->start())->toBe(LineRecorder::Pcov)
        ->and($recorded->stop(LineRecorder::Pcov, ['/project/src/Known.php' => [3, 4]]))->toBe([
            ['/project/src/Money.php' => [11, 12], '/project/src/Known.php' => [3]],
            ['/project/src/Money.php' => [11, 12, 14], '/project/src/Unrun.php' => [7, 8]],
        ])
        ->and($collector->calls())->toBe(['clear', 'start', 'stop', 'collect']);
});

it('records nothing where neither outbreak.so nor a collector can', function (): void {
    $recorded = new Recorded(collector: false);

    expect($recorded->start())->toBeFalse()
        ->and($recorded->stop(LineRecorder::Pcov))->toBe([[], []]);
});

it('takes the lines outbreak.so recorded, none where it is not loaded', function (): void {
    expect(new Recorded(new LineCollectorFake())->stop(LineRecorder::Outbreak))->toBe([[], []]);
})->skip(fn(): bool => function_exists('outbreak_lines_stop'), 'outbreak.so records the lines of this very process.');

it('collects through pcov only where PCOV_ENABLED switches it on, as pcov reads it', function (string $enabled, bool $usable): void {
    $inProcess = Environment::during([Recorded::ENABLED => $enabled], static fn(): Recorded => Recorded::inProcess());

    expect($inProcess)->toEqual(new Recorded($usable && function_exists('pcov\start') ? new PcovLines() : false));
})->with([
    'a one' => ['1', true],
    'a word in any case' => ['On', true],
    'a nought' => ['0', false],
    'nothing' => ['', false],
]);
