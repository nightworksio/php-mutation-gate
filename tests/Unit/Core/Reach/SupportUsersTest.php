<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\SupportUsers;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * Who uses the changed support among these files, by path, in a project of one tree.
 *
 * @param array<string, string> $files
 */
function supportUsersAmong(array $files): SupportUsers
{
    $sources = Sources::none();

    foreach ($files as $path => $text) {
        $sources = $sources->withNow(Path::of($path), Contents::of($text));
    }

    return SupportUsers::in(
        Layout::standard(Paths::of(Path::of('tests/Pest.php'))),
        Packages::of(Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())))),
        $sources,
    );
}

$fake = "<?php\n\nnamespace Tests\\Fakes;\n\nfinal class ClockFake {}\n";

$clockFake = static fn(): Names => Names::of('Tests\Fakes\ClockFake');

it('finds each test that names the changed support, through the support that names it in turn', function () use ($fake, $clockFake): void {
    $found = supportUsersAmong([
        'tests/Support/Ledgers.php' => "<?php\n\nnamespace Tests\\Support;\n\nfinal class Ledgers { public function clock(): Clocks {} }\n",
        'tests/Support/Clocks.php' => "<?php\n\nnamespace Tests\\Support;\n\nuse Tests\\Fakes\\ClockFake;\n\nfinal class Clocks { public ClockFake \$clock; }\n",
        'tests/Fakes/ClockFake.php' => $fake,
        'tests/Unit/ClockTest.php' => "<?php\n\nuse Tests\\Fakes\\ClockFake;\n\nit('ticks', fn () => new ClockFake());\n",
        'tests/Unit/MoneyTest.php' => "<?php\n\nit('adds', fn () => 1);\n",
        'tests/Unit/LedgerTest.php' => "<?php\n\nuse Tests\\Support\\Ledgers;\n\nit('books', fn () => new Ledgers());\n",
        'src/Money.php' => "<?php\n\nnamespace App;\n\nfinal class Money {}\n",
        'README.md' => 'Tests\Fakes\ClockFake',
    ])->of(Path::of('tests/Fakes/ClockFake.php'), $clockFake(), 'the project');

    expect($found)->toEqual(Paths::of(Path::of('tests/Unit/ClockTest.php'), Path::of('tests/Unit/LedgerTest.php')));
});

it('finds no test where nothing names the changed support', function () use ($fake, $clockFake): void {
    $found = supportUsersAmong([
        'tests/Fakes/ClockFake.php' => $fake,
        'tests/Unit/MoneyTest.php' => "<?php\n\nit('adds', fn () => 1);\n",
    ])->of(Path::of('tests/Fakes/ClockFake.php'), $clockFake(), 'the project');

    expect($found)->toEqual(Paths::none());
});

it('reaches the whole package where support that names it runs code when it is loaded', function () use ($fake, $clockFake): void {
    $found = supportUsersAmong([
        'tests/Fakes/ClockFake.php' => $fake,
        'tests/Datasets/Clocks.php' => "<?php\n\nuse Tests\\Fakes\\ClockFake;\n\ndataset('clocks', [new ClockFake()]);\n",
        'tests/Unit/ClockTest.php' => "<?php\n\nuse Tests\\Fakes\\ClockFake;\n\nit('ticks', fn () => new ClockFake());\n",
    ])->of(Path::of('tests/Fakes/ClockFake.php'), $clockFake(), 'packages/money');

    expect($found)->toEqual(Reason::that('`tests/Datasets/Clocks.php` runs code when it is loaded, so every unit of packages/money is reached.'));
});

it('reaches the whole package where the changed support itself runs code when it is loaded', function () use ($clockFake): void {
    $found = supportUsersAmong([
        'tests/Fakes/ClockFake.php' => "<?php\n\nnamespace Tests\\Fakes;\n\nfinal class ClockFake {}\n\nClockFake::register();\n",
    ])->of(Path::of('tests/Fakes/ClockFake.php'), $clockFake(), 'the project');

    expect($found)->toEqual(Reason::that('`tests/Fakes/ClockFake.php` runs code when it is loaded, so every unit of the project is reached.'));
});

it('reaches the whole package where something that is no test names the changed support', function () use ($fake, $clockFake): void {
    $found = supportUsersAmong([
        'tests/Fakes/ClockFake.php' => $fake,
        'tests/Unit/ClockTest.php' => "<?php\n\nuse Tests\\Fakes\\ClockFake;\n\nit('ticks', fn () => new ClockFake());\n",
        'src/Wiring.php' => "<?php\n\nnamespace App;\n\nfinal class Wiring { public const string CLOCK = \\Tests\\Fakes\\ClockFake::class; }\n",
    ])->of(Path::of('tests/Fakes/ClockFake.php'), $clockFake(), 'the project');

    expect($found)->toEqual(Reason::that('`src/Wiring.php` names the changed support and is no test, so every unit of the project is reached.'));
});
