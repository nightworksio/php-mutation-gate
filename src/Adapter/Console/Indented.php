<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use function explode;

use NightWorksIO\MutationGate\Core\Report\TestsText;

use function sprintf;

/** Lines as the console nests them under a heading: each one of them indented once, a blank one left as it is. */
final readonly class Indented
{
    /**
     * @param  list<string> $lines each a line, or several joined by line breaks
     * @return list<string>
     */
    public static function lines(array $lines): array
    {
        $indented = [];

        foreach ($lines as $line) {
            foreach (explode("\n", $line) as $part) {
                $indented[] = $part === '' ? '' : sprintf('%s%s', TestsText::INDENT, $part);
            }
        }

        return $indented;
    }
}
