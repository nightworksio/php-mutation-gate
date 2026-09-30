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
    $project = Project::at($root, $tests, Path::of('.mutation-gate'));

    expect(TestFiles::in($project))->toEqual(Paths::of(
        Path::of('tests/MoneySpec.php'),
        Path::of('tests/Unit/Deep/HeldTest.php'),
        Path::of('tests/dir.php/InsideTest.php'),
        Path::of('more/OtherTest.php'),
    ));
});

it('selects every file whose class name ends in a class the filter names', function (): void {
    $files = Paths::of(
        Path::of('tests/MoneySpec.php'),
        Path::of('tests/Unit/BigMoneySpec.php'),
        Path::of('tests/Money-Spec.php'),
        Path::of('tests/Held%41Spec.php'),
        Path::of('tests/MoneySpecial.php'),
        Path::of('tests/LegacySpec.php'),
    );

    expect(TestFiles::naming($files, ['MoneySpec', 'HeldSpec']))->toEqual(Paths::of(
        Path::of('tests/MoneySpec.php'),
        Path::of('tests/Unit/BigMoneySpec.php'),
        Path::of('tests/Money-Spec.php'),
        Path::of('tests/Held%41Spec.php'),
    ))->and(TestFiles::naming($files, []))->toEqual(Paths::none());
});
