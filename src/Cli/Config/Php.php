<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_diff_key;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Document;

use function preg_match_all;
use function sort;
use function sprintf;

/**
 * A config written as a `mutation-gate.php` (ADR-0002): `Gate::configure()`
 * and one call for each setting it holds, which reads back into the same
 * config.
 */
final readonly class Php
{
    /** Settings written as one call each, `%s` taking the value. */
    private const array VALUES = [
        'baseline.path' => 'Baseline::at(%s)',
        'holds.hotPath' => 'Reach::hotPath(%s)',
        'shards.seconds' => 'Shards::seconds(%s)',
        'shards.max' => 'Shards::max(%s)',
        'ci.defaultBranch' => 'Ci::defaultBranch(%s)',
        'ci.gitlab.template' => 'Ci::gitlabTemplate(%s)',
        'budget' => 'Budget::of(%s)',
        'timeouts.seconds' => 'Timeouts::seconds(%s)',
        'timeouts.retries' => 'Timeouts::retries(%s)',
        'ignores.maxDays' => 'Ignores::within(%s)',
        'pest.canary' => 'Pest::canary(%s)',
        'local.watchBudget' => 'Local::watchBudget(%s)',
        'local.prePushBudget' => 'Local::prePushBudget(%s)',
    ];

    /** Settings that are a word, written as one call for each word. */
    private const array WORDS = [
        'uncovered' => ['count' => 'Uncovered::counted()', 'exclude' => 'Uncovered::excluded()'],
        'baseline.improvement' => [
            'require' => 'Baseline::requiringImprovement()',
            'report' => 'Baseline::reportingImprovement()',
        ],
        'proofs.write' => ['auto' => 'Proofs::writing()', 'never' => 'Proofs::readOnly()'],
        'timeouts.mode' => ['confirm' => 'Timeouts::confirmed()', 'unjudged' => 'Timeouts::unjudged()'],
        'ignores.native' => [
            'refuse' => 'Ignores::refusingNativeMarkers()',
            'allow' => 'Ignores::allowingNativeMarkers()',
        ],
    ];

    /** Settings that are true or false, written as the call for true and the call for false. */
    private const array FLAGS = [
        'flaky.confirmSurvivors' => ['Flaky::confirmingSurvivors()', 'Flaky::notConfirmingSurvivors()'],
        'pest.patch' => ['Pest::patched()', 'Pest::unpatched()'],
    ];

    /** Settings that are a list, written as one call with every entry. */
    private const array LISTS = [
        'packages' => 'Reach::packages(%s)',
        'reach.everything' => 'Reach::everything(%s)',
        'proofs.ignore' => 'Proofs::ignore(%s)',
    ];

    /** Settings that are a map, written as one call for each entry. */
    private const array MAPS = [
        'costs.secondsPerLine' => 'Shards::secondsPerLine(%s, %s)',
        'badge.colors' => 'Badge::colour(%s, %s)',
    ];

    /** The order `with()` takes the settings in: the order of the configuration reference. */
    private const array ORDER = [
        'uncovered', 'baseline.path', 'baseline.improvement', 'packages', 'reach.everything', 'holds.hotPath',
        'shards.seconds', 'shards.max', 'costs.secondsPerLine', 'ci.plan', 'ci.defaultBranch', 'ci.gitlab.template',
        'ci.buildkite.step', 'proofs.store', 'proofs.ignore', 'proofs.write', 'budget', 'timeouts.mode',
        'timeouts.seconds', 'timeouts.retries', 'flaky.confirmSurvivors', 'ignores.maxDays', 'ignores.native',
        'badge.colors', 'pest.patch', 'pest.canary', 'local.watchBudget', 'local.prePushBudget',
    ];

    /** The settings `Gate` has a method of its own for, in the order it is called in. */
    private const array GATE = [
        'extensions' => 'extensions',
        'preset' => 'preset',
        'runner' => 'runner',
        'treeSource' => 'treeSource',
        'trees' => 'trees',
        'newCode.floor' => 'newCode',
        'ignores.entries' => 'ignoring',
        'reports' => 'reporting',
    ];

    public static function render(Document $document): string
    {
        $config = Json::decode($document->json());
        $tree = is_array($config) ? $config : [];
        $code = implode('', [...self::gate($tree), ...self::with($tree)]);
        preg_match_all('/\b([A-Z][A-Za-z]+)::/', $code, $classes);
        $used = array_unique(['Gate', ...$classes[1]]);
        sort($used);

        return sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n%s\nreturn Gate::configure()%s;\n",
            implode('', array_map(
                static fn(string $class): string => sprintf("use NightWorksIO\\MutationGate\\Config\\%s;\n", $class),
                $used,
            )),
            $code,
        );
    }

    /**
     * The calls on `Gate` for the settings it has a method of its own for. An empty list is left out, but
     * for `trees`, where an empty list means no tree at all.
     *
     * @param  array<mixed> $tree
     * @return list<string>
     */
    private static function gate(array $tree): array
    {
        $calls = [];

        foreach (self::GATE as $path => $method) {
            $value = self::at($tree, $path);

            if (! $value instanceof Absent && ($value !== [] || $path === 'trees')) {
                $calls[] = self::call($method, self::arguments($path, $value));
            }
        }

        return $calls;
    }

    /** @return list<string> */
    private static function arguments(string $path, mixed $value): array
    {
        return match ($path) {
            'extensions' => array_map(
                static fn(mixed $class): string => sprintf('Load::extension(%s)', PhpValues::literal($class)),
                self::entries($value),
            ),
            'preset' => array_map(self::preset(...), self::entries($value)),
            'runner' => [PhpValues::choice('Runner', $value)],
            'treeSource' => [self::source($value)],
            'trees' => array_map(PhpValues::tree(...), self::maps($value)),
            'newCode.floor' => [sprintf('Floor::of(%s)', PhpValues::literal($value))],
            'ignores.entries' => array_map(PhpValues::ignore(...), self::maps($value)),
            default => array_map(PhpValues::report(...), self::maps($value)),
        };
    }

    /**
     * The calls in `with()` for every other setting the config holds.
     *
     * @param  array<mixed> $tree
     * @return list<string>
     */
    private static function with(array $tree): array
    {
        $settings = [];

        foreach (self::ORDER as $path) {
            $value = self::at($tree, $path);

            if (! $value instanceof Absent) {
                $settings = [...$settings, ...self::setting($path, $value)];
            }
        }

        return $settings === [] ? [] : [self::call('with', $settings)];
    }

    /** @return list<string> */
    private static function setting(string $path, mixed $value): array
    {
        return match (true) {
            array_key_exists($path, self::VALUES) => [sprintf(self::VALUES[$path], PhpValues::literal($value))],
            array_key_exists($path, self::WORDS) => [self::WORDS[$path][is_string($value) ? $value : '']],
            array_key_exists($path, self::FLAGS) => [self::FLAGS[$path][$value === true ? 0 : 1]],
            array_key_exists($path, self::LISTS) => [sprintf(self::LISTS[$path], PhpValues::literals($value))],
            array_key_exists($path, self::MAPS) => self::entriesOf(self::MAPS[$path], $value),
            $path === 'ci.plan' => [PhpValues::choice('Ci', $value)],
            $path === 'ci.buildkite.step' => [
                sprintf('Ci::buildkiteStep(%s)', implode(', ', PhpValues::options(is_array($value) ? $value : []))),
            ],
            default => [PhpValues::store($value)],
        };
    }

    /**
     * A map, as one call for each of its entries.
     *
     * @return list<string>
     */
    private static function entriesOf(string $call, mixed $map): array
    {
        $calls = [];

        foreach (is_array($map) ? $map : [] as $key => $value) {
            $calls[] = sprintf($call, PhpValues::literal(sprintf('%s', $key)), PhpValues::literal($value));
        }

        return $calls;
    }

    private static function preset(mixed $name): string
    {
        return match ($name) {
            'library', 'laravel', 'symfony' => sprintf('Preset::%s()', $name),
            default => sprintf('Preset::named(%s)', PhpValues::literal($name)),
        };
    }

    /** The tree source, by `Source::phpunit()` with its fallback paths where it is that one. */
    private static function source(mixed $source): string
    {
        $use = is_array($source) ? $source['use'] : $source;
        $with = is_array($source) && is_array($source['with']) ? $source['with'] : [];
        $fallback = array_key_exists('fallback', $with) ? $with['fallback'] : [];

        return $use === 'phpunit' && array_diff_key($with, ['fallback' => true]) === []
            ? sprintf('Source::phpunit(%s)', PhpValues::literals($fallback))
            : PhpValues::choice('Source', $source);
    }

    /** @param list<string> $arguments */
    private static function call(string $method, array $arguments): string
    {
        $lines = array_map(static fn(string $argument): string => sprintf("\n        %s,", $argument), $arguments);

        return match (count($arguments)) {
            0 => sprintf("\n    ->%s()", $method),
            1 => sprintf("\n    ->%s(%s)", $method, $arguments[0]),
            default => sprintf("\n    ->%s(%s\n    )", $method, implode('', $lines)),
        };
    }

    /**
     * The entries of a list that are objects.
     *
     * @return list<array<mixed>>
     */
    private static function maps(mixed $list): array
    {
        $maps = [];

        foreach (is_array($list) ? $list : [] as $entry) {
            if (is_array($entry)) {
                $maps[] = $entry;
            }
        }

        return $maps;
    }

    /** @return list<mixed> a list as it is, a single value as a list of one */
    private static function entries(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }

    /** @param array<mixed> $tree */
    private static function at(array $tree, string $path): mixed
    {
        $value = $tree;

        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return Absent::setting();
            }

            $value = $value[$key];
        }

        return $value;
    }
}
