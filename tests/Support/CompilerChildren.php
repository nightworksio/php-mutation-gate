<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function file_put_contents;
use function sprintf;
use function var_export;

/** The programs the tests of the opcache compiler compile, and the children they start in place of PHP. */
final readonly class CompilerChildren
{
    public const string PROGRAM = "<?php\nfunction six(): int { return 2 * 3; }\n";

    /** What a child writes to the dump's stream: each of these sections after the marker. */
    public static function dump(string ...$sections): string
    {
        $written = '';

        foreach ($sections as $section) {
            $written .= sprintf("\x1Emutation-gate\x1E%s", $section);
        }

        return $written;
    }

    /** A child that compiles nothing, but answers both of two programs compiled, writes this dump, and exits so. */
    public static function child(string $dump, int $exit): string
    {
        $child = sprintf('%s/fake-php', Scratch::directory());
        file_put_contents($child, sprintf(
            "#!%s\n<?php\nfwrite(STDERR, %s);\necho '11';\nexit(%d);\n",
            PHP_BINARY,
            var_export($dump, return: true),
            $exit,
        ));
        chmod($child, 0o755);

        return $child;
    }
}
