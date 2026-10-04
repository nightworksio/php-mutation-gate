<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Pest\Printed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use RuntimeException;

/** Code as Pest writes a mutant's copy of a file: printed whole by php-parser's standard printer. */
final class PestPrint
{
    public static function of(string $code): string
    {
        $printed = Printed::of(Contents::of($code), Path::of('src/Printed.php'));

        return $printed instanceof Contents ? $printed->text() : throw new RuntimeException($printed->why());
    }
}
