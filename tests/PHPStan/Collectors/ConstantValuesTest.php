<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Analysed;

/**
 * What the one-home rule reports over these files, each message as far as the classes it names.
 *
 * @param array<string, string> $files
 *
 * @return list<string>
 */
function oneHomeOver(array $files): array
{
    $services = <<<'NEON'
            -
                class: NightWorksIO\MutationGate\PHPStan\Collectors\ConstantValues
                tags:
                    - phpstan.collector
            -
                class: NightWorksIO\MutationGate\PHPStan\Rules\OneHomePerValueRule
                arguments:
                    coincidences:
                        'Planted\Coincidence':
                            - SIZES
                tags:
                    - phpstan.rules.rule
        NEON;

    return array_map(static fn(string $message): string => explode('. One value', $message)[0], Analysed::messages($files, $services));
}

/** A class of the planted namespace with these declarations. */
function plantedClass(string $name, string ...$declarations): string
{
    return sprintf(
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace Planted;\n\nfinal readonly class %s\n{\n%s\n}\n",
        $name,
        implode("\n", array_map(static fn(string $declaration): string => sprintf('    %s', $declaration), $declarations)),
    );
}

it('compares each item of an array constant, at any depth, and the array whole', function (): void {
    expect(oneHomeOver([
        'Runners.php' => plantedClass('Runners', "public const array NAMES = ['pest', ['nested' => 'infection']];"),
        'Pest.php' => plantedClass('Pest', "public const string NAME = 'pest';"),
        'Infection.php' => plantedClass('Infection', "public const string NAME = 'infection';"),
        'Copy.php' => plantedClass('Copy', "public const array NAMES = ['pest', ['nested' => 'infection']];"),
    ]))->toBe([
        "Copy.php:9 D9 — Planted\\Copy::NAMES holds 'infection', and so does Planted\\Infection::NAME, Planted\\Runners::NAMES",
        "Copy.php:9 D9 — Planted\\Copy::NAMES holds 'pest', and so does Planted\\Pest::NAME, Planted\\Runners::NAMES",
        "Copy.php:9 D9 — Planted\\Copy::NAMES holds array{'pest', array{nested: 'infection'}}, and so does Planted\\Runners::NAMES",
        "Copy.php:9 D9 — Planted\\Copy::NAMES holds array{nested: 'infection'}, and so does Planted\\Runners::NAMES",
        "Infection.php:9 D9 — Planted\\Infection::NAME holds 'infection', and so does Planted\\Copy::NAMES, Planted\\Runners::NAMES",
        "Pest.php:9 D9 — Planted\\Pest::NAME holds 'pest', and so does Planted\\Copy::NAMES, Planted\\Runners::NAMES",
        "Runners.php:9 D9 — Planted\\Runners::NAMES holds 'infection', and so does Planted\\Copy::NAMES, Planted\\Infection::NAME",
        "Runners.php:9 D9 — Planted\\Runners::NAMES holds 'pest', and so does Planted\\Copy::NAMES, Planted\\Pest::NAME",
        "Runners.php:9 D9 — Planted\\Runners::NAMES holds array{'pest', array{nested: 'infection'}}, and so does Planted\\Copy::NAMES",
        "Runners.php:9 D9 — Planted\\Runners::NAMES holds array{nested: 'infection'}, and so does Planted\\Copy::NAMES",
    ]);
});

it('takes an item written as a constant, a global constant or an enum case as referring to its home', function (): void {
    expect(oneHomeOver([
        'Runner.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Planted;\n\nenum Runner: string\n{\n    case Pest = 'pest';\n}\n",
        'Pest.php' => plantedClass('Pest', "public const string NAME = 'phpunit';"),
        'Runners.php' => plantedClass('Runners', 'public const array NAMES = [Runner::Pest->value, Pest::NAME, PHP_EOL, PHP_OS_FAMILY];'),
        'Os.php' => plantedClass('Os', 'public const string FAMILY = PHP_OS_FAMILY;'),
    ]))->toBe([]);
});

it('leaves out every item of an array named a coincidence', function (): void {
    expect(oneHomeOver([
        'Coincidence.php' => plantedClass('Coincidence', "public const array SIZES = ['large', 'small'];"),
        'Large.php' => plantedClass('Large', "public const string WORD = 'large';"),
    ]))->toBe([]);
});
