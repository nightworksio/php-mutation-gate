<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function intdiv;
use function mb_convert_encoding;
use function preg_split;
use function strlen;

/**
 * H10 — the lines of a file longer than SonarCloud's S103 allows, measured as
 * S103 measures them: in UTF-16 units, the way Java counts a string, with the
 * file split at every `\r\n`, `\r` and `\n`.
 */
final readonly class LongLines
{
    /** SonarCloud's S103 threshold in the PSR-2 profile this project is scanned with. */
    public const int LONGEST = 120;

    /** The bytes a UTF-16 unit takes. */
    private const int UNIT = 2;

    /**
     * Each line over the threshold, by its number from 1, with its length.
     *
     * @return array<int, int>
     */
    public static function in(string $code): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $code);
        $long = [];

        foreach ($lines === false ? [] : $lines as $index => $line) {
            $length = intdiv(strlen(mb_convert_encoding($line, 'UTF-16LE', 'UTF-8')), self::UNIT);

            if ($length > self::LONGEST) {
                $long[$index + 1] = $length;
            }
        }

        return $long;
    }
}
