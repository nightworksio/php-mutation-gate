<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function implode;

use NightWorksIO\MutationGate\PHPStan\Rules\NoManyMethodsRule;

use function range;
use function sprintf;
use function str_repeat;

/**
 * The smallest violation of every rule in ARCHITECTURE.md, and where it is
 * planted. The Guards suite plants them all in a throwaway copy of the tree,
 * runs each machine once over the copy, and requires every rule to report its
 * own fixture by name.
 */
final readonly class Fixtures
{
    /** @return list<Fixture> */
    public static function all(): array
    {
        return [
            ...self::layers(),
            ...self::inputAndOutput(),
            ...self::refusals(),
            ...self::types(),
            ...self::namesAndSize(),
            ...self::theLanguage(),
            ...self::tests(),
            ...self::theRulesThemselves(),
            ...self::theBotsTables(),
        ];
    }

    /** @return list<Fixture> */
    private static function layers(): array
    {
        return [
            Fixture::suite('A1', 'src/Core/PlantedReachOut.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                use Symfony\Component\Console\Output\OutputInterface;

                final readonly class PlantedReachOut
                {
                    public function __construct(private OutputInterface $output) {}
                }
                PHP, 'keeps the core, the ports, the config and the extension API free of every framework, and the mutator SDK of all but php-parser'),
            Fixture::suite('A1', 'src/Mutator/PlantedReachOut.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Mutator;

                use Symfony\Component\Console\Output\OutputInterface;

                final readonly class PlantedReachOut
                {
                    public function __construct(private OutputInterface $output) {}
                }
                PHP, 'keeps the core, the ports, the config and the extension API free of every framework, and the mutator SDK of all but php-parser'),
            Fixture::suite('A2', 'src/Port/PlantedConcrete.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Port;

                final readonly class PlantedConcrete {}
                PHP, 'declares nothing but interfaces as ports'),
            Fixture::suite('A3', 'src/Adapter/Pest/PlantedCrossing.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Adapter\Pest;

                use NightWorksIO\MutationGate\Adapter\Infection\Anything;

                final readonly class PlantedCrossing
                {
                    public function __construct(private Anything $other) {}
                }
                PHP, 'keeps every adapter apart from every other'),
            Fixture::suite('A4', 'src/Config/PlantedUpward.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Config;

                use NightWorksIO\MutationGate\Cli\Anything;

                final readonly class PlantedUpward
                {
                    public static function of(Anything $cli): self
                    {
                        return new self();
                    }
                }
                PHP, 'lets a layer name only itself and the layers before it'),
            Fixture::suite('A6', 'src/Adapter/Pest/PlantedPluginReach.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Adapter\Pest;

                use NightWorksIO\MutationGateDefault\DefaultSet;

                final readonly class PlantedPluginReach
                {
                    public function __construct(private DefaultSet $set) {}
                }
                PHP, 'keeps src from naming any plugin'),
            Fixture::suite('A7', 'plugins/default/src/PlantedInternals.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGateDefault;

                use NightWorksIO\MutationGate\Adapter\Pest\Diff;

                final readonly class PlantedInternals
                {
                    public function __construct(private Diff $diff) {}
                }
                PHP, 'lets a plugin name only PHP, php-parser, the mutator SDK, the extension API and the core values they reach'),
            Fixture::suite('A8', 'plugins/default/src/PlantedDependency.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGateDefault;

                use Symfony\Component\Process\Process;

                final readonly class PlantedDependency
                {
                    public function __construct(private Process $process) {}
                }
                PHP, 'gives each plugin a manifest of its own that requires every package its code names'),
            Fixture::suite('A5', 'src/Attribute/PlantedReach.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Attribute;

                use NightWorksIO\MutationGate\Core\File\Path;

                final readonly class PlantedReach
                {
                    public static function at(Path $path): self
                    {
                        return new self();
                    }
                }
                PHP, 'keeps the attribute to PHP alone, and named by nothing else under src'),
        ];
    }

    /** @return list<Fixture> */
    private static function inputAndOutput(): array
    {
        return [
            self::inTheCore('B1', 'PlantedClock', 'return time();', 'int', 'B1 — time is an input'),
            self::inTheCore('B2', 'PlantedDice', 'return random_int(1, 6);', 'int', 'B2 — randomness'),
            self::inTheCore('B3', 'PlantedFile', "return (string) file_get_contents('composer.json');", 'string', 'B3 — the filesystem'),
            self::inTheCore('B4', 'PlantedProgram', "return (string) shell_exec('git status');", 'string', 'B4 — running a program'),
            self::inTheCore('B5', 'PlantedSocket', "return curl_init('https://example.com') !== false;", 'bool', 'B5 — the network'),
            self::inTheCore('B6', 'PlantedEnvironment', "return (string) getenv('CI');", 'string', 'B6 — the environment'),
            self::inTheCore('B7', 'PlantedWait', 'return sleep(1);', 'int', 'B7 — waiting'),
        ];
    }

    /** @return list<Fixture> */
    private static function refusals(): array
    {
        return [
            Fixture::suite('C1', 'src/Port/PlantedSilence.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Port;

                interface PlantedSilence
                {
                    public function forget(): void;
                }
                PHP, 'lets no port answer with nothing'),
            Fixture::suite('C2', 'src/Port/PlantedAbsence.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Port;

                interface PlantedAbsence
                {
                    public function found(): ?string;
                }
                PHP, 'passes no null across the public API'),
            Fixture::suite('C2', 'src/Core/PlantedMaybe.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                final readonly class PlantedMaybe
                {
                    public function __construct(private ?string $name) {}

                    public function name(): string
                    {
                        return $this->name ?? '';
                    }
                }
                PHP, 'lets nothing in the core be null'),
            self::inTheCore('C3', 'PlantedBareThrow', "throw new \\RuntimeException('bare');", 'never', 'C3 — a bare exception'),
            self::inTheCore('C4', 'PlantedSilencedCall', 'return @intdiv(1, 0);', 'int', 'ergebnis.noErrorSuppression'),
            self::inTheCore('C5', 'PlantedElse', "if (PHP_VERSION_ID > 0) {\n        return 1;\n    } else {\n        return 0;\n    }", 'int', 'C5 — no `else`'),
            self::inTheCore('C5', 'PlantedSwitch', "switch (PHP_VERSION_ID) {\n        default:\n            return 1;\n    }", 'int', 'ergebnis.noSwitch'),
            self::inTheCore('C6', 'PlantedSwallow', "try {\n        return intdiv(1, 1);\n    } catch (\\DivisionByZeroError) {\n    }\n\n    return 0;", 'int', 'C6 —'),
            self::inTheCore('C7', 'PlantedEmpty', 'return empty($_SERVER);', 'bool', 'C7 —'),
            self::inTheCore('C8', 'PlantedNullsafe', 'return (new \\ArrayObject())?->count() ?? 0;', 'int', 'C8 —'),
            self::inTheCore('C9', 'PlantedNestedTernary', 'return PHP_VERSION_ID > 1 ? 1 : (PHP_VERSION_ID > 0 ? 0 : 1);', 'int', 'C9 — a ternary inside a ternary'),
            self::inTheCore('C9', 'PlantedCoalesce', "return ['a' => 1]['b'] ?? 0;", 'int', 'C9 — `??` on an array subscript'),
        ];
    }

    /** @return list<Fixture> */
    private static function types(): array
    {
        return [
            Fixture::suite('D1', 'src/Port/PlantedList.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Port;

                interface PlantedList
                {
                    /** @return list<string> */
                    public function all(): array;
                }
                PHP, 'passes no array across the public API'),
            Fixture::suite('D2', 'src/Config/PlantedPrimitive.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Config;

                final readonly class PlantedPrimitive
                {
                    public function at(string $path): self
                    {
                        return $this;
                    }
                }
                PHP, 'takes a primitive only in a named constructor on the public API'),
            Fixture::suite('D3', 'src/Extension/PlantedAnything.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Extension;

                interface PlantedAnything
                {
                    public function take(mixed $value): self;
                }
                PHP, 'says what every value on the public API is'),
            self::inTheCore('D3', 'PlantedUntyped', 'return 1;', '', 'return type'),
            Fixture::suite('D4', 'src/Core/PlantedStatus.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                final readonly class PlantedStatus {}
                PHP, 'names a closed set as an enum rather than a class'),
            self::inTheCore('D5', 'PlantedFlag', "return in_array(1, [1], true) ? 1 : 0;", 'int', 'D5 —'),
            self::inTheCore('D6', 'PlantedNumber', 'return 600;', 'int', 'D6 — give 600 a name'),
            self::inTheCore('D8', 'PlantedWords', "return match (PHP_OS_FAMILY) {\n        'Linux' => 'l',\n        'Darwin' => 'd',\n        'Windows' => 'w',\n        default => '',\n    };", 'string', 'D8 — this match'),
            Fixture::analyser('D9', 'src/Core/PlantedTwins.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                final readonly class PlantedTwins
                {
                    public const string SUFFIX = '.planted';
                }

                final readonly class PlantedOtherTwin
                {
                    public const string SUFFIX = '.planted';
                }
                PHP, 'D9 — NightWorksIO\MutationGate\Core\PlantedTwins::SUFFIX holds'),
            self::inTheCore('D10', 'PlantedMixed', 'return 1;', 'mixed', 'D10 — this type says mixed'),
            Fixture::analyser('D11', 'src/Adapter/Pest/PlantedOpenProperty.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Adapter\Pest;

                final class PlantedOpenProperty
                {
                    public int $count = 0;
                }
                PHP, 'D11 — $count is public and not readonly'),
            Fixture::suite('D7', 'src/Core/PlantedOpen.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                class PlantedOpen {}
                PHP, 'seals every class, and keeps every value readonly'),
        ];
    }

    /** @return list<Fixture> */
    private static function namesAndSize(): array
    {
        return [
            Fixture::suite('H1', 'src/Core/PlantedManager.php', self::aValueCalled('PlantedManager'), 'names no class for being vague'),
            Fixture::suite('H2', 'src/Core/PlantedInterface.php', self::aValueCalled('PlantedInterface'), 'names no type for being an interface or abstract'),
            Fixture::analyser('H3', 'src/Core/PlantedCrowd.php', self::aClassWithTooManyMethods(), 'H3 — this class declares 21 methods'),
            Fixture::suite('H4', 'tests/Unit/Core/PlantedOrphanTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                it('describes a class that is not there', function (): void {
                    expect(true)->toBeTrue();
                });
                PHP, 'keeps every unit test beside the file it tests'),
            self::inTheCore('H5', 'PlantedConcat', "return 'shard ' . PHP_VERSION;", 'string', 'H5 — build this with sprintf rather than concatenation'),
            self::inTheCore('H5', 'PlantedJoin', "return 'one '\n        . 'two';", 'string', 'H5 — write this as one literal'),
            self::inTheCore('H5', 'PlantedInterpolation', 'return "PHP {$_SERVER[\'x\']}";', 'string', 'H5 — build this with sprintf rather than interpolation'),
            Fixture::suite('H6', 'src/Core/PlantedException.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                use RuntimeException;

                final class PlantedException extends RuntimeException {}
                PHP, 'names an exception for what happened'),
            Fixture::suite('H7', 'tests/Unit/Core/PlantedIdentifierTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                it('A1 — keeps the core apart', function (): void {
                    expect(true)->toBeTrue();
                });
                PHP, 'names every test for its behaviour, without an identifier'),
            self::inTheCore('H8', 'PlantedDoors', "if (PHP_VERSION_ID === 1) {\n        return 1;\n    }\n\n    if (PHP_VERSION_ID === 2) {\n        return 2;\n    }\n\n    if (PHP_VERSION_ID === 3) {\n        return 3;\n    }\n\n    return 0;", 'int', 'H8 — this method returns from 4 places'),
            self::inTheCore('H9', 'PlantedHug', "return sprintf('%s', implode(\n        ',',\n        ['a'],\n    ));", 'string', 'H9 — put every item of this list on line'),
            self::inTheCore('H10', 'PlantedLongLine', sprintf("return '%s';", str_repeat('a', 130)), 'string', 'H10 — this line is 144 characters long'),
            Fixture::analyser('H11', 'src/Core/PlantedJson5.php', self::aValueCalled('PlantedJson5'), 'H11 — name PlantedJson5 in letters only'),
            Fixture::suite('W1', 'src/Core/PlantedMisplaced.php', self::aValueCalled('PlantedSomewhereElse'), 'declares one class per file, the one its path names'),
            Fixture::suite('K1', 'src/Core/PlantedHistory.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                // This previously read the manifest.
                final readonly class PlantedHistory {}
                PHP, 'keeps every comment to what is true now'),
        ];
    }

    /** @return list<Fixture> */
    private static function theLanguage(): array
    {
        return [
            Fixture::analyser('P1', 'src/Core/PlantedMagic.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                final readonly class PlantedMagic
                {
                    public function __get(string $name): string
                    {
                        return $name;
                    }
                }
                PHP, 'P1 — __get()'),
            self::inTheCore('P2', 'PlantedDynamic', "\$class = 'ArrayObject';\n\n    return (new \$class())->count();", 'int', 'P2 —'),
            self::inTheCore('P3', 'PlantedArguments', 'return count(func_get_args());', 'int', 'P3 — func_get_args()'),
            self::inTheCore('P4', 'PlantedReflection', "return (new \\ReflectionClass('ArrayObject'))->getName();", 'string', 'P4 —'),
            self::inTheCore('Q1', 'PlantedSetting', "return (string) ini_set('memory_limit', '1G');", 'string', 'Q1 —'),
            Fixture::analyser('Q3', 'src/Core/PlantedLateBinding.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                final readonly class PlantedLateBinding
                {
                    public static function make(): self
                    {
                        return new static();
                    }
                }
                PHP, 'Q3 —'),
            self::inTheCore('Q4', 'PlantedEcho', "echo 'x';\n\n    return 1;", 'int', 'Q4 —'),
            self::inTheCore('L3', 'PlantedBytes', "return strlen('één');", 'int', 'L3 —'),
            self::inTheCore('S1', 'PlantedDanger', 'return phpinfo();', 'bool', 'phpinfo()'),
            Fixture::audit(
                'S2',
                "\"name\": \"symfony/process\",\n            \"version\": \"v8.1.7\",",
                "\"name\": \"symfony/process\",\n            \"version\": \"v7.1.6\",",
                'CVE-2024-51736',
            ),
        ];
    }

    /** @return list<Fixture> */
    private static function tests(): array
    {
        return [
            Fixture::suite('G1', 'tests/Unit/Core/PlantedMockTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                it('stands a mock in', function (): void {
                    $clock = Mockery::mock(Psr\Clock\ClockInterface::class);

                    expect($clock)->not->toBeNull();
                });
                PHP, 'mocks nothing'),
            Fixture::suite('G2', 'src/Port/PlantedUnproven.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Port;

                interface PlantedUnproven
                {
                    public function answer(): self;
                }
                PHP, 'proves every port against its fake and against every adapter'),
            Fixture::dependencies('G4', 'src/Core/PlantedDevOnly.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core;

                use Symfony\Component\Finder\Finder;

                final readonly class PlantedDevOnly
                {
                    public function __construct(private Finder $finder) {}
                }
                PHP),
            Fixture::suite('G5', 'tests/Unit/Core/PlantedAssertTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                it('asserts the other way', function (): void {
                    $this->assertTrue(true);
                });
                PHP, 'asserts with expect and nothing else'),
            Fixture::suite('G6', 'tests/Unit/Core/PlantedFocusTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                it('runs alone', function (): void {
                    expect(true)->toBeTrue();
                })->only();
                PHP, 'commits no focused test and no unexplained skip'),
            Fixture::coverage('G7', 'src/Core/Planted/Uncovered.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace NightWorksIO\MutationGate\Core\Planted;

                /** A class no test reaches. */
                final readonly class Uncovered
                {
                    public static function reached(): string
                    {
                        return 'never';
                    }
                }
                PHP, 'Core/Planted/Uncovered'),
            Fixture::notDrivable('G8', 'Planting a survivor means running mutation testing over a copy, which runs in CI only, never in this suite.'),
            Fixture::edit('G9', 'phpunit.xml', 'failOnWarning="true"', 'failOnWarning="false"', 'fails the run on every diagnostic', 'failOnWarning is not'),
        ];
    }

    /** @return list<Fixture> */
    private static function theRulesThemselves(): array
    {
        return [
            Fixture::direct('R1', 'TheRulesAreRealTest: "finds a rule that claims a mechanism nothing of that kind carries" hands the judgement a rule whose mechanism does not carry it.'),
            Fixture::direct('R2', 'EveryRuleRefusesAViolationTest: "names a rule that nothing is planted under" hands the judgement a rule with no fixture.'),
            Fixture::edit('R3', 'rector.php', "        __DIR__ . '/phpstan',\n", '', 'reads the same trees with the analyser, the refactorer and the Arch suite', 'rector.php does not refactor'),
        ];
    }

    /** @return list<Fixture> */
    private static function theBotsTables(): array
    {
        return [
            Fixture::edit('V1', '.github/gates.json', '"scope": {', '"scoped": {', 'has an entry in gates.json for every CI job', 'scope has no entry'),
            Fixture::edit('V2', '.github/workflows/pr.yml', "        run: python3 .github/scripts/gate_summary.py commitlint\n", "        run: echo\n", 'leaves evidence from every CI job', 'commitlint does not end by explaining its failure'),
            Fixture::edit('V3', '.github/gates.json', '"typo": "A word typos', '"typing": "A word typos', 'explains every rule the recorded evidence names', 'typos: typo'),
            Fixture::edit('V4', '.github/bot/rules/pint.json', '"says": "All classes must be final', '"says": "Every class is final', 'holds the generated rule tables to what the locked tools say', 'pint.json is not what the locked tool says'),
            Fixture::edit('V5', '.github/workflows/bot-commands.yml', "        run: python3 .github/scripts/bot_command.py docs\n", "        run: python3 .github/scripts/bot_command.py docs \"\${{ github.event.comment.body }}\"\n", 'runs no pull request content where it can write', 'bot-commands.yml: docs runs'),
        ];
    }

    /**
     * A core class with one method whose body is the violation.
     *
     * `$returns` is the method's return type, or nothing for none.
     */
    private static function inTheCore(string $rule, string $class, string $body, string $returns, string $marker): Fixture
    {
        return Fixture::analyser($rule, sprintf('src/Core/%s.php', $class), sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace NightWorksIO\MutationGate\Core;

            final readonly class %s
            {
                public function planted()%s
                {
                %s
                }
            }
            PHP, $class, $returns === '' ? '' : sprintf(': %s', $returns), $body), $marker);
    }

    private static function aValueCalled(string $class): string
    {
        return sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace NightWorksIO\MutationGate\Core;

            final readonly class %s {}
            PHP, $class);
    }

    private static function aClassWithTooManyMethods(): string
    {
        $methods = [];

        foreach (range(1, NoManyMethodsRule::THE_MOST_METHODS + 1) as $at) {
            $methods[] = sprintf("    public function answer%d(): int\n    {\n        return 1;\n    }\n", $at);
        }

        return sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace NightWorksIO\MutationGate\Core;

            final readonly class PlantedCrowd
            {
            %s}
            PHP, implode("\n", $methods));
    }
}
