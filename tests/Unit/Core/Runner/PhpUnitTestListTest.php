<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitTestList;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** A list of tests as PHPUnit's `--list-tests-xml` writes one: two classes, a phpt file, and two groups. */
const PHPUNIT_TEST_LIST = <<<'XML'
    <?xml version="1.0"?>
    <testSuite xmlns="https://xml.phpunit.de/testSuite">
     <tests>
      <testClass name="Tests\MoneyTest" file="/project/tests/MoneyTest.php">
       <testMethod id="Tests\MoneyTest::testAdds" name="testAdds"/>
       <testMethod id="Tests\MoneyTest::testAdds#one" name="testAdds"/>
      </testClass>
      <phpt file="/project/tests/cli.phpt"/>
      <testClass name="Tests\KernelTest" file="/project/tests/KernelTest.php">
       <testMethod id="Tests\KernelTest::testBoots" name="testBoots"/>
      </testClass>
     </tests>
     <groups>
      <group name="default">
       <test id="Tests\MoneyTest::testAdds"/>
       <test id="Tests\MoneyTest::testAdds#one"/>
      </group>
      <group name="holds:src/Kernel.php">
       <test id="Tests\KernelTest::testBoots"/>
      </group>
     </groups>
    </testSuite>
    XML;

it('reads every test a class lists, by its id, and each group with its tests', function (): void {
    $listing = PhpUnitTestList::listedIn(Ran::finished(succeeded: true, output: ''), PHPUNIT_TEST_LIST, 'tests.xml', Program::Pest);

    expect($listing instanceof CannotJudge ? $listing : [$listing->tests(), $listing->groups(), $listing->inGroup(Group::named('holds:src/Kernel.php'))])
        ->toEqual([
            TestIds::of(TestId::of('Tests\MoneyTest::testAdds'), TestId::of('Tests\MoneyTest::testAdds#one'), TestId::of('Tests\KernelTest::testBoots')),
            Groups::of(Group::named('default'), Group::named('holds:src/Kernel.php')),
            TestIds::of(TestId::of('Tests\KernelTest::testBoots')),
        ]);
});

it('reads a list of no test and no group as an empty listing', function (): void {
    $empty = "<?xml version=\"1.0\"?>\n<testSuite xmlns=\"https://xml.phpunit.de/testSuite\"><tests/><groups/></testSuite>";
    $listing = PhpUnitTestList::listedIn(Ran::finished(succeeded: true, output: ''), $empty, 'tests.xml', Program::PhpUnit);

    expect($listing instanceof CannotJudge ? $listing : [count($listing->tests()), count($listing->groups())])->toBe([0, 0]);
});

it('cannot judge a run that failed, a file that is empty or no XML, or XML that is no list of tests', function (
    bool $succeeded,
    string $xml,
): void {
    expect(PhpUnitTestList::listedIn(Ran::finished(succeeded: $succeeded, output: 'Fatal error'), $xml, '/w/tests.xml', Program::Pest))
        ->toEqual(CannotJudge::because("Pest did not list the tests of the suite: /w/tests.xml holds no list of tests. It said:\nFatal error"));
})->with([
    'a failed run, though the file holds a list' => [false, PHPUNIT_TEST_LIST],
    'an empty file' => [true, ''],
    'no XML' => [true, 'not XML'],
    'XML that is no list of tests' => [true, '<?xml version="1.0"?><phpunit/>'],
]);

it('names the program that listed nothing', function (): void {
    expect(PhpUnitTestList::listedIn(Ran::finished(succeeded: false, output: 'no'), '', 'tests.xml', Program::PhpUnit))
        ->toEqual(CannotJudge::because("PHPUnit did not list the tests of the suite: tests.xml holds no list of tests. It said:\nno"));
});
