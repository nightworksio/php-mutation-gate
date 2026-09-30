<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;

use function sprintf;

/**
 * One config laid over another (ADR-0002): zero-config defaults, then
 * presets, then the config file, then the command line. Maps merge by key,
 * lists concatenate without repeating an entry, and anything else the later
 * layer sets replaces the earlier.
 */
final readonly class Layers
{
    /** The runner, which a layer chooses whole, but whose `withhold` only ever grows (ADR-0004). */
    private const string RUNNER = 'runner';

    private const string WITHHOLD = 'withhold';

    /**
     * The settings a later layer replaces whole. Declaring trees never adds them to the ones a source found,
     * and a setting that chooses an adapter chooses one, options and all.
     */
    private const array WHOLE = [
        'trees' => true,
        'treeSource' => true,
        'ci.plan' => true,
        'proofs.store' => true,
    ];

    public static function over(Document $earlier, Document $later): Document|CannotJudge
    {
        $merged = self::merged(Json::decode($earlier->json()), Json::decode($later->json()));

        return Document::ofJson(Json::encode($merged));
    }

    /** One decoded config laid over another. */
    public static function merged(mixed $earlier, mixed $later): mixed
    {
        return self::at($earlier, $later, '');
    }

    /**
     * The runner the later layer chooses, whole, or the earlier one's where the later only withholds, with what
     * both withhold.
     */
    public static function runner(mixed $earlier, mixed $later): mixed
    {
        $chosen = self::onlyWithholds($later) ? $earlier : $later;
        $withhold = self::concatenated(self::withheld($earlier), self::withheld($later));

        return $withhold === []
            ? $chosen
            : [...is_array($chosen) ? $chosen : ['use' => $chosen], self::WITHHOLD => $withhold];
    }

    /** Whether a runner as a layer writes it holds `withhold` and nothing else. */
    public static function onlyWithholds(mixed $runner): bool
    {
        return is_array($runner) && array_keys($runner) === [self::WITHHOLD];
    }

    private static function at(mixed $earlier, mixed $later, string $at): mixed
    {
        return match (true) {
            $at === self::RUNNER => self::runner($earlier, $later),
            array_key_exists($at, self::WHOLE), ! is_array($earlier), ! is_array($later) => $later,
            array_is_list($earlier) && array_is_list($later) => self::concatenated($earlier, $later),
            default => self::mergedMaps($earlier, $later, $at),
        };
    }

    /**
     * @param  list<mixed> $earlier
     * @param  list<mixed> $later
     * @return list<mixed>
     */
    private static function concatenated(array $earlier, array $later): array
    {
        $merged = $earlier;

        foreach ($later as $entry) {
            if (! in_array($entry, $merged, strict: true)) {
                $merged[] = $entry;
            }
        }

        return $merged;
    }

    /**
     * @param  array<mixed> $earlier
     * @param  array<mixed> $later
     * @return array<mixed>
     */
    private static function mergedMaps(array $earlier, array $later, string $at): array
    {
        $merged = $earlier;

        foreach ($later as $key => $value) {
            $merged[$key] = array_key_exists($key, $earlier)
                ? self::at($earlier[$key], $value, At::key($at, sprintf('%s', $key)))
                : $value;
        }

        return $merged;
    }

    /** @return list<mixed> what a runner as a layer writes it withholds */
    private static function withheld(mixed $runner): array
    {
        $withhold = is_array($runner) && array_key_exists(self::WITHHOLD, $runner) ? $runner[self::WITHHOLD] : [];

        return is_array($withhold) && array_is_list($withhold) ? $withhold : [];
    }
}
