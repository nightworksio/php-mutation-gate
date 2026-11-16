<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;

/** Whether a map of this text is whole, read from where it is asked to be. */
$wholeAt = static fn(string $text): bool => PhpReport::isWhole(
    strlen($text),
    static fn(int $from): string => substr($text, $from),
);

it('reads a map as whole only where it ends as php-code-coverage ends one', function () use ($wholeAt): void {
    expect($wholeAt("<?php return [];\nEND_OF_COVERAGE_SERIALIZATION\n);\n"))->toBeTrue()
        ->and($wholeAt("END_OF_COVERAGE_SERIALIZATION\n);"))->toBeTrue()
        ->and($wholeAt("<?php return \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\na:1:{"))->toBeFalse()
        ->and($wholeAt(''))->toBeFalse()
        ->and($wholeAt(sprintf("%s\nEND_OF_COVERAGE_SERIALIZATION\n);x", str_repeat('a', 9000))))->toBeFalse();
});

it('reads as far back as the whitespace after a map reaches', function () use ($wholeAt): void {
    expect($wholeAt(sprintf("<?php\nEND_OF_COVERAGE_SERIALIZATION\n);%s", str_repeat(" \n\t\r\v\0", 3000))))->toBeTrue()
        ->and($wholeAt(sprintf("%s\n);%s", str_repeat('x', 5000), str_repeat("\n", 20000))))->toBeFalse()
        ->and($wholeAt(str_repeat("\n", 9000)))->toBeFalse();
});

it('says why a map cannot be read', function (): void {
    expect(PhpReport::missingAt('/x/coverage.php'))
        ->toEqual(CannotJudge::because('There is no coverage map at /x/coverage.php, so no test runs any line.'))
        ->and(PhpReport::cutOffAt('/x/coverage.php'))
        ->toEqual(CannotJudge::because('/x/coverage.php cannot be read as a coverage map: it ends before the map does.'))
        ->and(PhpReport::unreadableAt('/x/coverage.php', 'it is empty'))
        ->toEqual(CannotJudge::because('/x/coverage.php cannot be read as a coverage map: it is empty'));
});
