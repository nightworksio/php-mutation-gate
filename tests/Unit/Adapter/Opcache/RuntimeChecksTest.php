<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\RuntimeChecks;
use NightWorksIO\MutationGate\Core\File\Contents;

/** Whether a mutant made by replacing this text of a program changes what a runtime check decides. */
function runtimeReached(string $program, string $from, string $to): bool
{
    $mutant = str_replace($from, $to, $program);

    return $mutant !== $program && RuntimeChecks::in(Contents::of($program))->reached(Contents::of($mutant));
}

const CHECKED = <<<'PHP_WRAP'
<?php

final class Sizes
{
    public const BITS = PHP_INT_SIZE * 8;

    public const BYTES = 4 * 2;

    public function wide(): int
    {
        $wide = \FUNCTION_EXISTS('strlen');

        return $wide ? 1 : 2;
    }

    public function narrow(): int
    {
        return 3 + 4;
    }

    public function later(): Closure
    {
        $plain = 5 + 6;

        return static fn(): int => PHP_OS === 'Linux' ? $plain : 7;
    }
}
PHP_WRAP;

it('reaches what a check decides anywhere in the function it is in, and nothing in another', function (): void {
    expect(runtimeReached(CHECKED, '$wide ? 1 : 2', '$wide ? 1 : 9'))->toBeTrue()
        ->and(runtimeReached(CHECKED, 'public function wide(): int', 'public function wide(): float'))->toBeTrue()
        ->and(runtimeReached(CHECKED, '3 + 4', '3 - 4'))->toBeFalse();
});

it('reaches only the closure a check is in, not the function around it', function (): void {
    expect(runtimeReached(CHECKED, "'Linux' ? \$plain : 7", "'Linux' ? \$plain : 8"))->toBeTrue()
        ->and(runtimeReached(CHECKED, '5 + 6', '5 - 6'))->toBeFalse();
});

it('reaches only the constant of a class a check is in', function (): void {
    expect(runtimeReached(CHECKED, 'PHP_INT_SIZE * 8', 'PHP_INT_SIZE * 9'))->toBeTrue()
        ->and(runtimeReached(CHECKED, '4 * 2', '4 * 3'))->toBeFalse();
});

it('reaches the whole program where a check stands in code outside any function or class', function (): void {
    $program = "<?php\n\n\$os = PHP_OS_FAMILY;\n\nfunction plain(): int\n{\n    return 1 + 2;\n}\n";

    expect(runtimeReached($program, '1 + 2', '1 - 2'))->toBeTrue();
});

it('reaches the whole of a program that does not parse', function (): void {
    expect(runtimeReached("<?php\n\nfunction (\n", '(', '['))->toBeTrue();
});

it('takes no other name for a check: a function, a method or a constant named alike, or a check named as the other kind', function (
    string $program,
): void {
    expect(runtimeReached($program, '1 + 2', '1 - 2'))->toBeFalse();
})->with([
    'a function named alike' => ["<?php\n\nfunction plain(): int\n{\n    my_function_exists('x');\n\n    return 1 + 2;\n}\n"],
    'a method named as a check' => ["<?php\n\nfunction plain(object \$o): int\n{\n    \$o->defined('x');\n\n    return 1 + 2;\n}\n"],
    'a constant named alike' => ["<?php\n\nfunction plain(): int\n{\n    \$x = PHP_OS_X;\n\n    return 1 + 2;\n}\n"],
    'a constant named as a call' => ["<?php\n\nfunction plain(): int\n{\n    \$x = defined;\n\n    return 1 + 2;\n}\n"],
    'a constant written in another case' => ["<?php\n\nfunction plain(): int\n{\n    \$x = php_os;\n\n    return 1 + 2;\n}\n"],
    'a call through a variable' => ["<?php\n\nfunction plain(string \$f): int\n{\n    \$f('x');\n\n    return 1 + 2;\n}\n"],
]);

it('reaches a change on the first or last byte of what a check decides, and none just outside it', function (): void {
    $program = "<?php\n\nfunction a(): int { return PHP_INT_SIZE; }\nfunction b(): int { return 1; }\n";

    expect(runtimeReached($program, 'function a()', 'function c()'))->toBeTrue()
        ->and(runtimeReached($program, "PHP_INT_SIZE; }\n", "PHP_INT_SIZE; )\n"))->toBeTrue()
        ->and(runtimeReached($program, "PHP_INT_SIZE; }\n", "PHP_INT_SIZE; } \n"))->toBeFalse()
        ->and(runtimeReached($program, "\n\nfunction a()", "\n;function a()"))->toBeFalse()
        ->and(runtimeReached($program, "}\nfunction b()", "}\n\nfunction b()"))->toBeFalse()
        ->and(runtimeReached($program, 'PHP_INT_SIZE; }', 'PHP_INT_SIZE;}'))->toBeTrue()
        ->and(runtimeReached($program, 'function a()', 'Function a()'))->toBeTrue()
        ->and(runtimeReached($program, "\n\nfunction a()", "\n\n#[A]\nfunction a()"))->toBeTrue();
});

it('reaches what a check decides where a change takes away the bytes just before it and its first', function (): void {
    $program = "<?php\n\nf();\nfunction a(): int { return PHP_INT_SIZE; }\nfunction b(): int { return 1; }\n";

    expect(runtimeReached($program, "\nf();", ''))->toBeTrue();
});
