<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A JUnit log holding this text, by its path. */
function testTimesLog(string $text): string
{
    $directory = Scratch::directory();
    Scratch::write($directory, 'junit.xml', $text);

    return sprintf('%s/junit.xml', $directory);
}

it('sums the time of each test case a JUnit log names, and leaves out the time of the suites that hold them', function (): void {
    $log = testTimesLog(<<<'XML'
        <testsuites><testsuite name="T" time="9.0">
          <testsuite name="A" time="8.0"><testcase name="a" time="0.25"/></testsuite>
          <testcase name="b" time="1.5"/>
        </testsuite></testsuites>
        XML);

    expect(TestTimes::in($log))->toEqual(Seconds::of(1.75));
});

it('times nothing where there is no log', function (): void {
    expect(TestTimes::in(sprintf('%s/junit.xml', Scratch::directory())))->toEqual(Unmeasured::duration());
});

it('times nothing where the log is not JUnit, names no test, or leaves a test untimed', function (string $text): void {
    expect(TestTimes::in(testTimesLog($text)))->toEqual(Unmeasured::duration());
})->with([
    'not XML' => ['a log cut short'],
    'no test' => ['<testsuites><testsuite name="T" time="1.0"/></testsuites>'],
    'a test untimed' => ['<testsuites><testcase name="a" time="0.25"/><testcase name="b"/></testsuites>'],
]);
