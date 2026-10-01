<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\TestFiles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('finds the file in the test directories that declares each test\'s class', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/Unit/MoneyTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class MoneyTest {}");
    Scratch::write($root, 'tests/Unit/Misnamed.php', "<?php\nnamespace Tests\\Unit;\nfinal class PriceTest {}");
    Scratch::write($root, 'src/PriceTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class PriceTest {}");
    $tests = new TestFiles(Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.gate')));
    $asked = TestIds::of(TestId::of('Tests\Unit\MoneyTest::testAdds'), TestId::of('Tests\Unit\PriceTest::testRounds'));

    expect($tests->declaring($asked)->byClass())->toEqual(['Tests\Unit\MoneyTest' => Path::of('tests/Unit/MoneyTest.php')]);
});

it('walks the test directories once, however often it is asked', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/MoneyTest.php', "<?php\nnamespace Tests;\nfinal class MoneyTest {}");
    $tests = new TestFiles(Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.gate')));
    $money = TestIds::of(TestId::of('Tests\MoneyTest::testAdds'));
    $first = $tests->declaring($money)->files();
    Scratch::write($root, 'tests/PriceTest.php', "<?php\nnamespace Tests;\nfinal class PriceTest {}");

    expect($first)->toEqual(Paths::of(Path::of('tests/MoneyTest.php')))
        ->and($tests->declaring(TestIds::of(TestId::of('Tests\PriceTest::testRounds')))->files())->toEqual(Paths::none());
});
