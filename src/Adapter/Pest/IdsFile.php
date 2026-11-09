<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_all;
use function array_map;
use function file_put_contents;
use function implode;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * A file that names tests by their ids, one a line, which PHPUnit reads with
 * `--test-id-filter-file` to run exactly those (ADR-0138).
 *
 * @internal
 */
final readonly class IdsFile
{
    /** What the file a control's ids are named in adds to the name of its log. */
    public const string SUFFIX = '.ids';

    /** The file these tests are named in, written at this path; none where one cannot be read back, or written. */
    public static function written(TestIds $tests, string $file): string|NotGiven
    {
        $ids = array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
        $text = implode(PhpUnitOption::LINE_END, $ids) . PhpUnitOption::LINE_END;

        return $ids !== []
            && array_all($ids, PhpUnitOption::readsBack(...))
            && file_put_contents($file, $text) !== false
            ? $file
            : NotGiven::value();
    }
}
