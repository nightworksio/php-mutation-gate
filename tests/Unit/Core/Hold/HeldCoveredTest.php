<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('keeps of the tests covering a mutant those its held unit\'s holding tests run, and every one where no held unit holds its file', function (
    string $file,
    array $judging,
): void {
    $held = HeldCovered::of(
        Covered::by(Unit::held(Path::of('src/Kernel'), Group::named('holds:src/Kernel')), TestIds::of(TestId::of('KernelTest::a'))),
        Covered::by(Unit::held(Path::of('src/Boot.php'), Group::named('holds:src/Boot.php')), TestIds::of(TestId::of('BootTest::a'))),
    );
    $covering = TestIds::of(TestId::of('KernelTest::a'), TestId::of('BootTest::a'), TestId::of('SuiteTest::a'));

    expect(array_map(
        static fn(TestId $test): string => $test->value(),
        [...$held->judgingAmong(Path::of($file), $covering)],
    ))->toBe($judging);
})->with([
    'a file inside a held directory' => ['src/Kernel/Boot.php', ['KernelTest::a']],
    'a held file' => ['src/Boot.php', ['BootTest::a']],
    'a file beside a held directory' => ['src/KernelBoot.php', ['KernelTest::a', 'BootTest::a', 'SuiteTest::a']],
]);

it('counts the held units it knows to be covered, and lists them in the order given', function (): void {
    $kernel = Covered::by(Unit::held(Path::of('src/Kernel'), Group::named('holds:src/Kernel')), TestIds::of(TestId::of('KernelTest::a')));
    $boot = Covered::by(Unit::held(Path::of('src/Boot.php'), Group::named('holds:src/Boot.php')), TestIds::of(TestId::of('BootTest::a')));

    expect(count(HeldCovered::of($kernel, $boot)))->toBe(2)
        ->and([...HeldCovered::of($kernel, $boot)])->toBe([$kernel, $boot])
        ->and(count(HeldCovered::none()))->toBe(0)
        ->and([...HeldCovered::of($kernel)->with($boot)])->toBe([$kernel, $boot]);
});
