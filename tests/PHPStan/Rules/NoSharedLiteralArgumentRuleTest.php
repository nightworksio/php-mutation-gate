<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Analysed;

/** The shared-literal rule and its collector, with these methods allowed, each as NEON writes a class's list. */
function sharedLiteralServices(string ...$allowed): string
{
    return sprintf(<<<'NEON'
            -
                class: NightWorksIO\MutationGate\PHPStan\Collectors\LiteralArguments
                tags:
                    - phpstan.collector
            -
                class: NightWorksIO\MutationGate\PHPStan\Rules\NoSharedLiteralArgumentRule
                arguments:
                    analysedPaths: %%analysedPaths%%
                    configuredPaths: %%analysedPathsFromConfig%%
                    allowIn:
                        %s
                tags:
                    - phpstan.rules.rule
        NEON, $allowed === [] ? '{}' : implode(sprintf("\n%s", str_repeat(' ', 16)), $allowed));
}

/**
 * What the rule reports over these files, with nothing allowed.
 *
 * @param array<string, string> $files
 *
 * @return list<string>
 */
function sharedLiterals(array $files): array
{
    return Analysed::messages($files, sharedLiteralServices());
}

/** A file of the planted namespace holding this code. */
function planted(string $code): string
{
    return sprintf("<?php\n\ndeclare(strict_types=1);\n\nnamespace Planted;\n\n%s\n", $code);
}

it('reports a parameter two classes hand literals, at its method, naming the classes and the literals', function (): void {
    expect(sharedLiterals([
        'Words.php' => planted("final class Words\n{\n    public function say(string \$word): string\n    {\n        return \$word;\n    }\n}"),
        'First.php' => planted("final class First\n{\n    public function of(Words \$words): string\n    {\n        return \$words->say('hello');\n    }\n}"),
        'Second.php' => planted("final class Second\n{\n    public function of(Words \$words): string\n    {\n        return \$words->say('goodbye');\n    }\n}"),
    ]))->toBe([
        "Words.php:9 D12 — Planted\\Words::say() takes \$word as a string literal from Planted\\First, Planted\\Second: 'goodbye', 'hello'. "
        . 'What two classes spell is a closed set or a shared value: take an enum, or a constant they share (D12).',
    ]);
});

it('reads static calls, constructors, named arguments and variadic parameters alike', function (): void {
    $messages = sharedLiterals([
        'Words.php' => planted(implode("\n", [
            'final class Words',
            '{',
            '    public function __construct(public string $first = \'\', string ...$rest) {}',
            '',
            '    public static function of(string $kind, string $word): self',
            '    {',
            '        return new self($kind, $word);',
            '    }',
            '}',
        ])),
        'First.php' => planted("final class First\n{\n    public function of(): Words\n    {\n        return Words::of(word: 'ab', kind: 'cd');\n    }\n}"),
        'Second.php' => planted("final class Second\n{\n    public function of(): Words\n    {\n        return new Words('ef', 'gh', 'ij');\n    }\n}"),
        'Third.php' => planted("final class Third\n{\n    public function of(): Words\n    {\n        return Words::of('kl', 'mn') ?: new Words('op', 'qr');\n    }\n}"),
    ]);

    expect(array_map(static fn(string $message): string => explode('. What', $message)[0], $messages))->toBe([
        "Words.php:11 D12 — Planted\\Words::of() takes \$kind as a string literal from Planted\\First, Planted\\Third: 'cd', 'kl'",
        "Words.php:11 D12 — Planted\\Words::of() takes \$word as a string literal from Planted\\First, Planted\\Third: 'ab', 'mn'",
        "Words.php:9 D12 — Planted\\Words::__construct() takes \$first as a string literal from Planted\\Second, Planted\\Third: 'ef', 'op'",
        "Words.php:9 D12 — Planted\\Words::__construct() takes \$rest as a string literal from Planted\\Second, Planted\\Third: 'gh', 'ij', 'qr'",
    ]);
});

it('leaves alone one class spelling its own values, plain literals, values that are not literals and methods it does not own', function (): void {
    expect(sharedLiterals([
        'Words.php' => planted("final class Words\n{\n    public static function say(string \$word): string\n    {\n        return \$word;\n    }\n}"),
        'First.php' => planted(implode("\n", [
            'final class First',
            '{',
            '    public function of(string $word): string',
            '    {',
            "        return Words::say('one') . Words::say('two') . Words::say('') . Words::say('x') . Words::say(\$word)",
            "            . new \\SplFileInfo('first.php')->getFilename() . \\Nette\\Neon\\Neon::encode(\\Nette\\Neon\\Neon::decode('a: 1'));",
            '    }',
            '}',
        ])),
        'Second.php' => planted(implode("\n", [
            'final class Second',
            '{',
            '    public function of(string $word): string',
            '    {',
            "        return Words::say('') . Words::say('y') . Words::say(\$word) . Words::say(...['three'])",
            "            . new \\SplFileInfo('second.php')->getFilename() . \\Nette\\Neon\\Neon::encode(\\Nette\\Neon\\Neon::decode(input: 'b: 2'));",
            '    }',
            '}',
        ])),
    ]))->toBe([]);
});

it('leaves a method that phpstan.neon allows alone, and refuses an allowed method no two classes need any more', function (): void {
    $files = [
        'Words.php' => planted("final class Words\n{\n    public static function say(string \$word): string\n    {\n        return \$word;\n    }\n}"),
        'Quiet.php' => planted("final class Quiet\n{\n    public static function say(string \$word): string\n    {\n        return \$word;\n    }\n}"),
        'First.php' => planted("final class First\n{\n    public function of(): string\n    {\n        return Words::say('hello') . Quiet::say('hush');\n    }\n}"),
        'Second.php' => planted("final class Second\n{\n    public function of(): string\n    {\n        return Words::say('goodbye');\n    }\n}"),
    ];
    $allowed = sharedLiteralServices("'Planted\\Words': [say]", "'Planted\\Quiet': [say]");

    expect(Analysed::messages($files, $allowed))->toBe([
        'Quiet.php:9 D12 — phpstan.neon lets Planted\Quiet::say() take string literals from any class, and no two classes hand it one any more. '
        . 'Take it off allowIn: the list only shrinks (D12).',
    ])
        ->and(Analysed::messages($files, $allowed, ['Quiet.php', 'First.php']))->toBe([]);
});
