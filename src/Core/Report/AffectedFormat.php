<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/** How `affected` prints the tests it lists (ADR-0020, decision 1). */
enum AffectedFormat: string
{
    /** One test file a line, for PHPUnit's `--test-files-file`. */
    case Files = 'files';

    /** Each test file ended by a NUL byte, for `xargs -0`. */
    case Files0 = 'files0';

    /** One test id a line, for PHPUnit's `--test-id-filter-file`. */
    case Ids = 'ids';

    /** The whole answer, with each file's ids and reasons. */
    case Json = 'json';
}
