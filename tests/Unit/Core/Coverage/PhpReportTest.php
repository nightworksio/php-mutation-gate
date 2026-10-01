<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;

it('reads a map as whole only where it ends as php-code-coverage ends one', function (): void {
    expect(PhpReport::isWhole("<?php return [];\nEND_OF_COVERAGE_SERIALIZATION\n);\n"))->toBeTrue()
        ->and(PhpReport::isWhole("<?php return \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\na:1:{"))->toBeFalse();
});

it('says why a map cannot be read', function (): void {
    expect(PhpReport::missingAt('/x/coverage.php'))
        ->toEqual(CannotJudge::because('There is no coverage map at /x/coverage.php, so no test runs any line.'))
        ->and(PhpReport::cutOffAt('/x/coverage.php'))
        ->toEqual(CannotJudge::because('/x/coverage.php cannot be read as a coverage map: it ends before the map does.'))
        ->and(PhpReport::unreadableAt('/x/coverage.php', 'it is empty'))
        ->toEqual(CannotJudge::because('/x/coverage.php cannot be read as a coverage map: it is empty'));
});
