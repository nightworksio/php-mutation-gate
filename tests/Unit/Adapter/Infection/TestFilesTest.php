<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Infection\TestFiles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('finds the file named after each class that declares it, in the test directories', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/Unit/MoneyTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class MoneyTest {}");
    Scratch::write($root, 'tests/Other/MoneyTest.php', "<?php\nnamespace Tests\\Other;\nfinal class MoneyTest {}");
    Scratch::write($root, 'tests/GlobalTest.php', "<?php\nfinal class GlobalTest {}");
    Scratch::write($root, 'tests/Unit/Misnamed.php', "<?php\nnamespace Tests\\Unit;\nfinal class HeldTest {}");
    Scratch::write($root, 'src/HeldTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class HeldTest {}");
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));

    expect(TestFiles::byClass($project, ['Tests\Unit\MoneyTest', 'GlobalTest', 'Tests\Unit\HeldTest']))
        ->toEqual(['GlobalTest' => Path::of('tests/GlobalTest.php'), 'Tests\Unit\MoneyTest' => Path::of('tests/Unit/MoneyTest.php')]);
});

it('finds the files of the classes some tests are in', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/Unit/MoneyTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class MoneyTest {}");
    Scratch::write($root, 'tests/Unit/OtherTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class OtherTest {}");
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    $tests = TestIds::of(TestId::of('Tests\Unit\MoneyTest::testAdds'), TestId::of('Tests\Unit\MoneyTest::testSubtracts'));

    expect(TestFiles::declaring($project, $tests)->files())->toEqual(Paths::of(Path::of('tests/Unit/MoneyTest.php')));
});

it('holds the tests of each class a file declares, and of each class named after a file that is gone', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/Unit/MoneyTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class MoneyTest {}");
    Scratch::write($root, 'tests/Unit/OtherTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class OtherTest {}");
    Scratch::write($root, 'tests/Unit/PriceTest.php', "<?php\nnamespace Tests\\Legacy;\nfinal class PriceTest {}");
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    $tests = TestIds::of(
        TestId::of('Tests\Unit\MoneyTest::testAdds'),
        TestId::of('Tests\Unit\GoneTest::testGoes'),
        TestId::of('Tests\Unit\OtherTest::testOther'),
        TestId::of('Tests\Unit\PriceTest::testRounds'),
    );
    $files = Paths::of(
        Path::of('tests/Unit/MoneyTest.php'),
        Path::of('tests/Unit/GoneTest.php'),
        Path::of('tests/Unit/PriceTest.php'),
    );

    expect(TestFiles::holding($project, $files, $tests))->toEqual(TestIds::of(
        TestId::of('Tests\Unit\MoneyTest::testAdds'),
        TestId::of('Tests\Unit\GoneTest::testGoes'),
    ));
});
