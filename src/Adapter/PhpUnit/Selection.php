<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * How a mutant's run selects the tests that cover it, from a file in its
 * directory: by their ids, one to a line, or by the files their classes are
 * in, where PHPUnit cannot read an id back from a line.
 */
enum Selection: string
{
    case Ids = 'ids.txt';
    case Files = 'files.txt';

    /** By the tests' ids where PHPUnit can read each back, and by their files where it cannot. */
    public static function of(TestIds $tests): self
    {
        foreach ($tests as $test) {
            if (! PhpUnitOption::readsBack($test->value())) {
                return self::Files;
            }
        }

        return self::Ids;
    }

    /** The option that has PHPUnit read the selection from the file. */
    public function option(string $file): string
    {
        return match ($this) {
            self::Ids => sprintf('%s=%s', PhpUnitOption::TestIdFilterFile->value, $file),
            self::Files => sprintf('%s=%s', PhpUnitOption::TestFilesFile->value, $file),
        };
    }
}
