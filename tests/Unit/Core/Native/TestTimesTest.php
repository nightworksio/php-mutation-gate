<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Native\CoverageReport;
use NightWorksIO\MutationGate\Core\Native\TestTimes;

it('writes a test\'s time on a line of its own, its id encoded, and reads each back', function (): void {
    $text = sprintf('%s%s', TestTimes::line("A::a with data set \"one two\"\n", 0.25), TestTimes::line('B::b', 1.5));

    expect(TestTimes::line('B::b', 1.5))->toBe("1.500000 B%3A%3Ab\n")
        ->and(TestTimes::read($text))->toEqual([TimedTest::of("A::a with data set \"one two\"\n", 0.25), TimedTest::of('B::b', 1.5)]);
});

it('reads no time where there is no file, and passes over a line cut short or not of its form', function (): void {
    expect(TestTimes::read(text: false))->toBe([])
        ->and(TestTimes::read("0.5\nfast A%3A%3Aa\n0.5 \n1.0 B%3A%3Ab"))->toEqual([TimedTest::of('B::b', 1.0)]);
});

it('names the map each report is written to: the PHP report as it is, the XML coverage by its index, the times beside it', function (): void {
    expect(CoverageReport::Php->value)->toBe('coverage.php')
        ->and(CoverageReport::Xml->value)->toBe('coverage-xml')
        ->and(CoverageReport::Php->written('/c/coverage.php'))->toBe('/c/coverage.php')
        ->and(CoverageReport::Xml->written('/c/coverage-xml'))->toBe('/c/coverage-xml/index.xml')
        ->and(CoverageReport::timesBeside('/c/coverage-xml'))->toBe('/c/times.txt');
});
