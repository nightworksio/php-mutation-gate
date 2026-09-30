<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('finds every PHP file under the test directories, each directory in name order', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/notes.txt', 'not a test');
    Scratch::write($root, 'tests/MoneySpec.php', '<?php');
    Scratch::write($root, 'tests/Unit/Deep/HeldTest.php', '<?php');
    Scratch::write($root, 'tests/dir.php/InsideTest.php', '<?php');
    Scratch::write($root, 'more/OtherTest.php', '<?php');
    $tests = Paths::of(Path::of('tests'), Path::of('more'), Path::of('absent'));
    $project = Project::at($root, $tests, Path::of('.mutation-gate'), Path::of('vendor'));

    expect(new TestFiles($project)->all())->toEqual(Paths::of(
        Path::of('tests/MoneySpec.php'),
        Path::of('tests/Unit/Deep/HeldTest.php'),
        Path::of('tests/dir.php/InsideTest.php'),
        Path::of('more/OtherTest.php'),
    ));
});

it('selects every file whose class name ends in a class the filter names, answering each set once', function (): void {
    $root = Scratch::directory();

    foreach (['MoneySpec', 'Unit/BigMoneySpec', 'Money-Spec', 'Held%41Spec', 'MoneySpecial', 'LegacySpec'] as $file) {
        Scratch::write($root, sprintf('tests/%s.php', $file), '<?php');
    }

    $files = new TestFiles(Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor')));
    $named = $files->naming(['MoneySpec', 'HeldSpec', 'MoneySpec']);
    Scratch::write($root, 'tests/LaterMoneySpec.php', '<?php');

    expect($named)->toEqual(Paths::of(
        Path::of('tests/Held%41Spec.php'),
        Path::of('tests/Money-Spec.php'),
        Path::of('tests/MoneySpec.php'),
        Path::of('tests/Unit/BigMoneySpec.php'),
    ))->and($files->naming(['HeldSpec', 'MoneySpec']))->toBe($named)
        ->and($files->naming([]))->toEqual(Paths::none())
        ->and($files->all())->toHaveCount(6);
});

it('does not walk into a linked directory, which may lead back up into a loop', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/MoneyTest.php', '<?php');
    symlink('..', sprintf('%s/tests/loop', $root));
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));

    expect(new TestFiles($project)->all())->toEqual(Paths::of(Path::of('tests/MoneyTest.php')));
});
