<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function explode;
use function implode;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Mutant\Hunks;

use function str_starts_with;

use Symfony\Component\Console\Formatter\OutputFormatter;

use function trim;

/**
 * A unified diff from the one Pest keeps, which wraps each line in console
 * colour tags, escapes it for the console, and indents it by two spaces.
 */
final readonly class Diff
{
    private const string INDENT = '  ';

    public static function fromPest(string $diff): string
    {
        $lines = [];

        foreach (explode("\n", new OutputFormatter()->formatAndWrap($diff, 0)) as $line) {
            $lines[] = str_starts_with($line, self::INDENT) ? mb_substr($line, mb_strlen(self::INDENT)) : $line;
        }

        return Hunks::of(trim(implode("\n", $lines), "\n"));
    }
}
