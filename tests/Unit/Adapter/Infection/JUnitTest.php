<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\JUnit;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads each test class\'s seconds from its first suite, and each test\'s by its coverage id', function (): void {
    $root = Scratch::directory();
    InfectionRun::coverage($root, '/p/src', [], ['Tests\MoneyTest' => 0.25, 'Tests\DrainTest' => 1.5], [
        'Tests\MoneyTest::adds' => 0.125,
        'Tests\MoneyTest::adds with data set #0' => 0.0625,
        'Tests\MoneyTest::adds with data set "small amounts"' => 0.03125,
        'Tests\DrainTest::drains' => 1.5,
    ]);
    $junit = JUnit::at(DiskPath::of(sprintf('%s/junit.xml', $root)));

    expect($junit)->toBeInstanceOf(JUnit::class)
        ->and($junit instanceof JUnit ? $junit->classSeconds('Tests\MoneyTest') : 0.0)->toBe(0.25)
        ->and($junit instanceof JUnit ? $junit->classSeconds('Tests\DrainTest') : 0.0)->toBe(1.5)
        ->and($junit instanceof JUnit ? $junit->classSeconds('Tests\Nowhere') : -1.0)->toBe(0.0)
        ->and($junit instanceof JUnit ? $junit->tests() : [])->toBe([
            'Tests\MoneyTest::adds' => 0.125,
            'Tests\MoneyTest::adds#0' => 0.0625,
            'Tests\MoneyTest::adds#small amounts' => 0.03125,
            'Tests\DrainTest::drains' => 1.5,
        ]);
});

it('keeps the first suite of a name, as Infection reads it', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'junit.xml', '<testsuites><testsuite name="A" time="1.5"/><testsuite name="A" time="9"/></testsuites>');
    $junit = JUnit::at(DiskPath::of(sprintf('%s/junit.xml', $root)));

    expect($junit instanceof JUnit ? $junit->classSeconds('A') : 0.0)->toBe(1.5);
});

it('cannot judge a log that is not there or not XML', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'broken.xml', '<testsuites');

    foreach (['absent.xml', 'broken.xml'] as $name) {
        expect(JUnit::at(DiskPath::of(sprintf('%s/%s', $root, $name))))->toEqual(CannotJudge::because(sprintf(
            '%s/%s is not there or is not a JUnit log, so the gate cannot say how long the tests took.',
            $root,
            $name,
        )));
    }
});
