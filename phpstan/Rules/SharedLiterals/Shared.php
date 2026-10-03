<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\SharedLiterals;

use function array_column;
use function array_diff;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function ksort;
use function sort;
use function sprintf;

/**
 * A parameter that two or more classes hand a string literal: the method and
 * the parameter, where the method is declared, the classes, and the literals
 * as written.
 *
 * @phpstan-type Handed array{method: string, parameter: string, literal: string, caller: string, file: string, line: int}
 */
final readonly class Shared
{
    /** One class spells its own value; a second spelling it too shares it. */
    private const int CLASSES = 2;

    /**
     * @param list<string> $callers  the classes that hand it a literal, by name
     * @param list<string> $literals the literals they hand it, as written
     */
    private function __construct(
        public string $method,
        public string $parameter,
        public string $file,
        public int $line,
        public array $callers,
        public array $literals,
    ) {
    }

    /**
     * Every parameter among the literals a collector gathered, file by file,
     * that two or more classes hand one, but those of an allowed method.
     *
     * @param array<string, list<list<Handed>>> $collected each literal as the method, the parameter, the literal, the class handing it, and the method's file and line
     * @param list<string>                      $allowed   methods, as `Class::method`, whose parameters take literals from anywhere
     *
     * @return list<self>
     */
    public static function among(array $collected, array $allowed): array
    {
        $shared = [];

        foreach (self::byParameter($collected) as $handed) {
            $first = $handed[0];
            $callers = self::sorted(array_column($handed, 'caller'));

            if (count($callers) >= self::CLASSES && ! in_array($first['method'], $allowed, strict: true)) {
                $shared[] = new self(
                    $first['method'],
                    $first['parameter'],
                    $first['file'],
                    $first['line'],
                    $callers,
                    self::sorted(array_column($handed, 'literal')),
                );
            }
        }

        return $shared;
    }

    /**
     * The allowed methods that no two classes hand a literal any more.
     *
     * @param array<string, list<list<Handed>>> $collected
     * @param list<string>                      $allowed
     *
     * @return list<string>
     */
    public static function unneeded(array $collected, array $allowed): array
    {
        return array_values(array_diff($allowed, array_map(static fn(self $shared): string => $shared->method, self::among($collected, []))));
    }

    /**
     * @param array<string, list<list<Handed>>> $collected
     *
     * @return array<string, non-empty-list<Handed>> the literals each parameter is handed, by method and parameter
     */
    private static function byParameter(array $collected): array
    {
        $byParameter = [];

        foreach ($collected as $handedInAFile) {
            foreach (array_merge(...$handedInAFile) as $handed) {
                $byParameter[sprintf('%s $%s', $handed['method'], $handed['parameter'])][] = $handed;
            }
        }

        ksort($byParameter);

        return $byParameter;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> each once, in order
     */
    private static function sorted(array $names): array
    {
        $unique = array_values(array_unique($names));
        sort($unique);

        return $unique;
    }
}
