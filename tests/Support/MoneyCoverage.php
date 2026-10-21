<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function is_dir;
use function mkdir;

use NightWorksIO\MutationGate\Core\Runner\Ran;

use function sprintf;

/** PHPUnit's run of a project's suite under coverage, as a fake answers it: one test covers Money's sum. */
final readonly class MoneyCoverage
{
    /** The run, its map written where `--coverage-php` names it, ending so; failed where it names no file. */
    public static function ran(string $file, string $root, bool $succeeded, string $said): Ran
    {
        if ($file === '') {
            return Ran::finished(succeeded: false, output: 'No map named');
        }

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), recursive: true);
        }

        CoverageMaps::write(
            $file,
            sprintf('%s/', $root),
            ['src/Money.php' => [5 => [0]]],
            ['Tests\MoneyTest::testAdds'],
            ['Tests\MoneyTest::testAdds' => 0.5],
        );

        return Ran::finished(succeeded: $succeeded, output: $said);
    }
}
