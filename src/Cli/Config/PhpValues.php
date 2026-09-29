<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function var_export;

/**
 * The values of a config written as the PHP builder's calls: a literal,
 * an adapter with its options, a tree, an ignore, a report.
 */
final readonly class PhpValues
{
    /** The built-in names each adapter class has a method for. */
    private const array NAMED = [
        'Runner' => ['pest' => true, 'infection' => true],
        'Source' => ['composer' => true],
        'Ci' => ['github' => true, 'gitlab' => true, 'buildkite' => true, 'circleci' => true, 'json' => true],
        'Report' => ['json' => true, 'junit' => true, 'sarif' => true, 'html' => true],
    ];

    /** A string, number or boolean as PHP writes it. */
    public static function literal(mixed $value): string
    {
        return var_export($value, return: true);
    }

    /** Several values, each as PHP writes it, as arguments. */
    public static function literals(mixed $values): string
    {
        return implode(', ', array_map(self::literal(...), is_array($values) ? $values : []));
    }

    /**
     * An adapter a setting chooses, by a class's method for its name, or `uses()` with its options.
     *
     * @param 'Runner'|'Source'|'Ci' $class
     */
    public static function choice(string $class, mixed $choice): string
    {
        $use = is_array($choice) && is_string($choice['use']) ? $choice['use'] : $choice;
        $with = is_array($choice) && is_array($choice['with']) ? $choice['with'] : [];

        return is_string($use) && $with === [] && array_key_exists($use, self::NAMED[$class])
            ? sprintf('%s::%s()', $class, $use)
            : sprintf('%s::uses(%s)', $class, implode(', ', [self::literal($use), ...self::options($with)]));
    }

    /**
     * Options, each as the `Option` that writes it.
     *
     * @param  array<mixed> $options
     * @return list<string>
     */
    public static function options(array $options): array
    {
        $written = [];

        foreach ($options as $key => $value) {
            [$method, $arguments] = match (true) {
                ! is_array($value) => ['of', [self::literal($value)]],
                array_is_list($value) => ['list', array_map(self::literal(...), $value)],
                default => ['nested', self::options($value)],
            };
            $written[] = sprintf('Option::%s(%s)', $method, implode(', ', [self::literal($key), ...$arguments]));
        }

        return $written;
    }

    /** @param array<mixed> $tree */
    public static function tree(array $tree): string
    {
        $arguments = [self::literal($tree['path'])];

        if (array_key_exists('floor', $tree)) {
            $arguments[] = sprintf('floor: %s', self::literal($tree['floor']));
        }

        if (array_key_exists('reason', $tree)) {
            $arguments[] = sprintf('because: %s', self::literal($tree['reason']));
        }

        return sprintf('Tree::at(%s)', implode(', ', $arguments));
    }

    /** @param array<mixed> $ignore */
    public static function ignore(array $ignore): string
    {
        $until = array_key_exists('expires', $ignore) ? sprintf(', until: %s', self::literal($ignore['expires'])) : '';

        return array_key_exists('mutant', $ignore)
            ? sprintf(
                'Ignore::mutant(%s, because: %s%s)',
                self::literal($ignore['mutant']),
                self::literal($ignore['reason']),
                $until,
            )
            : sprintf(
                'Ignore::mutator(%s, in: %s, because: %s%s)',
                self::literal($ignore['mutator']),
                self::literal($ignore['path']),
                self::literal($ignore['reason']),
                $until,
            );
    }

    /** @param array<mixed> $report */
    public static function report(array $report): string
    {
        $path = array_key_exists('path', $report) ? $report['path'] : '';
        $with = array_key_exists('with', $report) && is_array($report['with']) ? $report['with'] : [];
        $use = $report['use'];

        return is_string($use) && $path !== '' && $with === [] && array_key_exists($use, self::NAMED['Report'])
            ? sprintf('Report::%s(%s)', $use, self::literal($path))
            : sprintf(
                'Report::uses(%s)',
                implode(', ', [self::literal($use), self::literal($path), ...self::options($with)]),
            );
    }

    /** The proof store, by `Proofs::directory()` or `Proofs::s3()` for the built-in ones. */
    public static function store(mixed $store): string
    {
        $use = is_array($store) ? $store['use'] : $store;
        $with = is_array($store) && is_array($store['with']) ? $store['with'] : [];

        return match ($use) {
            'directory' => sprintf('Proofs::directory(%s)', self::literals(array_values($with))),
            's3' => sprintf('Proofs::s3(%s)', implode(', ', array_map(
                static fn(string $option): string => sprintf('%s: %s', $option, self::literal($with[$option])),
                array_keys($with),
            ))),
            default => sprintf('Proofs::uses(%s)', implode(', ', [self::literal($use), ...self::options($with)])),
        };
    }
}
