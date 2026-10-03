<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
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

it('holds the tests of the class Pest declares for each file, and of each class a file declares itself', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/Unit/MoneySpec.php', "<?php\nit('adds', fn () => true);");
    Scratch::write($root, 'tests/Legacy/OldTest.php', "<?php\nnamespace Tests\\Legacy;\nfinal class OldTest {}");
    Scratch::write($root, 'tests/Other/MoneySpec.php', "<?php\nit('adds', fn () => true);");
    $files = new TestFiles(Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor')));
    $tests = TestIds::of(
        TestId::of('P\Tests\Unit\MoneySpec::__pest_evaluable_it_adds'),
        TestId::of('P\Tests\Unit\MoneySpec::__pest_evaluable_it_adds#1'),
        TestId::of('P\Tests\Other\MoneySpec::__pest_evaluable_it_adds'),
        TestId::of('Tests\Legacy\OldTest::testOld'),
        TestId::of('Tests\Legacy\GoneTest::testGone'),
        TestId::of('P\Tests\Unit\GoneSpec::__pest_evaluable_it_goes'),
        TestId::of('P\Tests\Unit\Dotted::__pest_evaluable_it_dots'),
        TestId::of('P\Tests\Unit\Dottedmore::__pest_evaluable_it_dots'),
        TestId::of('P\Root::__pest_evaluable_it_roots'),
    );
    $held = Paths::of(
        Path::of('tests/Unit/MoneySpec.php'),
        Path::of('tests/Legacy/OldTest.php'),
        Path::of('tests/Legacy/GoneTest.php'),
        Path::of('tests/Unit/Gone%41-Spec.php'),
        Path::of('tests/Unit/Dotted.more.php'),
        Path::of('lower/case/Spec.php'),
    );

    expect($files->holding($held, $tests))->toEqual(TestIds::of(
        TestId::of('Tests\Legacy\OldTest::testOld'),
        TestId::of('Tests\Legacy\GoneTest::testGone'),
        TestId::of('P\Tests\Unit\MoneySpec::__pest_evaluable_it_adds'),
        TestId::of('P\Tests\Unit\MoneySpec::__pest_evaluable_it_adds#1'),
        TestId::of('P\Tests\Unit\GoneSpec::__pest_evaluable_it_goes'),
        TestId::of('P\Tests\Unit\Dotted::__pest_evaluable_it_dots'),
    ))
        ->and($files->holding(Paths::of(Path::of('lower/case/Spec.php')), TestIds::of(TestId::of('P\Lower\case\Spec::x'))))
        ->toEqual(TestIds::of(TestId::of('P\Lower\case\Spec::x')));
});
