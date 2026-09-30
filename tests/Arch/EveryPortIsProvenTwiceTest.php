<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Api;
use NightWorksIO\MutationGate\Tests\Support\Layer;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// G2: every port has a hand-written fake in tests/Fakes and a contract suite
// in tests/Contract/<Port> that runs the same expectations against the fake and
// against every adapter of it, so a fake cannot say something the real thing
// does not.

it('proves every port against its fake and against every adapter', function (): void {
    $offenders = [];

    foreach (Api::classesUnder(Layer::Port->directory()) as $port) {
        // A port that is not an interface is A2's to refuse, and no class implements it.
        if (! $port->isInterface()) {
            continue;
        }

        $name = $port->getShortName();
        $contract = sprintf('tests/Contract/%1$s/%1$sTest.php', $name);
        $fakes = array_values(array_filter(
            Api::classesUnder('tests/Fakes'),
            static fn(ReflectionClass $fake): bool => $fake->implementsInterface($port->getName()),
        ));

        if ($fakes === []) {
            $offenders[] = sprintf('%s has no fake in tests/Fakes', $name);
        }

        if (! is_file(Tree::at($contract))) {
            $offenders[] = sprintf('%s has no contract suite at %s', $name, $contract);

            continue;
        }

        $suite = codeOf(sprintf('tests/Contract/%s', $name));

        foreach ([...$fakes, ...adaptersOf($port)] as $implementation) {
            if (! buildsIn($suite, $implementation->getShortName())) {
                $offenders[] = sprintf('%s does not run against %s', $contract, $implementation->getName());
            }
        }
    }

    // G2
    expect($offenders)->toBe([], sprintf(
        "These ports are not proven against both sides:\n  %s\n\nA fake the contract suite does not hold to the port says whatever its author assumed (G2).",
        implode("\n  ", $offenders),
    ));
});

it('takes only building an implementation for running against it, not importing or naming it', function (): void {
    $pest = 'Pest';
    $passing = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Adapter\Pest\Pest;

        // the Pest adapter (Pest), once installed
        $runner = Pest::class;
        PHP;

    expect(buildsIn(codeIn($passing), $pest))->toBeFalse()
        ->and(buildsIn(codeIn(sprintf('%s%s', $passing, "\n\$runner = new Pest(\$project);\n")), $pest))->toBeTrue()
        ->and(buildsIn(codeIn(sprintf('%s%s', $passing, "\n\$runner = Pest::at(\$root);\n")), $pest))->toBeTrue()
        ->and(buildsIn(codeIn(sprintf('%s%s', $passing, "\n\$runner = new PestFake();\n")), $pest))->toBeFalse();
});

/**
 * Every class under src/Adapter that implements a port.
 *
 * @param  ReflectionClass<object>       $port
 * @return list<ReflectionClass<object>>
 */
function adaptersOf(ReflectionClass $port): array
{
    return array_values(array_filter(
        Api::classesUnder(Layer::Adapter->directory()),
        static fn(ReflectionClass $adapter): bool => $adapter->implementsInterface($port->getName()),
    ));
}

/** The code of a contract suite: every PHP file beside its test, read as `codeIn()` reads one. */
function codeOf(string $directory): string
{
    $files = glob(sprintf('%s/*.php', Tree::at($directory)));
    $code = '';

    foreach (is_array($files) ? $files : [] as $file) {
        $code = sprintf('%s%s', $code, codeIn((string) file_get_contents($file)));
    }

    return $code;
}

/**
 * PHP less its comments and `use` lines, so that naming or importing a class
 * in passing is not taken for running against it.
 */
function codeIn(string $source): string
{
    $code = '';

    foreach (PhpToken::tokenize($source) as $token) {
        $code = $token->is([T_COMMENT, T_DOC_COMMENT]) ? $code : sprintf('%s%s', $code, $token->text);
    }

    return (string) preg_replace('/^use [^;]+;$/mu', '', $code);
}

/**
 * Whether a suite builds a class of a short name, as a dataset does: with
 * `new`, or through one of its static constructors.
 */
function buildsIn(string $suite, string $class): bool
{
    $name = preg_quote($class, '/');

    return preg_match(sprintf('/(?:\\bnew\\s+%1$s\\b|\\b%1$s::(?!class\\b))/u', $name), $suite) === 1;
}
