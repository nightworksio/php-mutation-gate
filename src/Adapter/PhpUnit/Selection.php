<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;
use function str_contains;
use function str_ends_with;

/**
 * How a mutant's run selects the tests that cover it, from a file in its
 * directory: by their ids, one to a line, or by the files their classes are
 * in, where an id cannot be a line. PHPUnit reads the ids a line at a time
 * and drops the carriage return before a line's end, so an id with a line
 * break in it, as a data set's name can have, or one that ends in a carriage
 * return, is not one it can read back.
 */
enum Selection: string
{
    case Ids = 'ids.txt';
    case Files = 'files.txt';

    /** How a line of the file ends. */
    public const string LINE_END = "\n";

    /** What PHPUnit drops before a line's end. */
    private const string CARRIAGE_RETURN = "\r";

    /** By the tests' ids where PHPUnit can read each back, and by their files where it cannot. */
    public static function of(TestIds $tests): self
    {
        foreach ($tests as $test) {
            $id = $test->value();

            if (str_contains($id, self::LINE_END) || str_ends_with($id, self::CARRIAGE_RETURN)) {
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
