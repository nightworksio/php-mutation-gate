<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;
use function array_map;
use function array_push;
use function array_values;
use function count;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function json_decode;
use function json_encode;

use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Turbo\Answer;
use NightWorksIO\MutationGate\Core\Turbo\Kind;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Protocol;
use NightWorksIO\MutationGate\Core\Turbo\Request;
use NightWorksIO\MutationGate\Port\Accelerator;

use function sort;
use function sprintf;

/**
 * An accelerator that answers in plain PHP, from the request alone: an entry
 * key from the entry's starting files and what they name, walked breadth
 * first, sorted as PHP sorts and hashed with the key's framing; a unit key
 * from its lines, each with the digest of its test set in byte order.
 */
final readonly class AcceleratorFake implements Accelerator
{
    public function answer(Request $request): Answer|NotAccelerated
    {
        /** @var array{protocol: int, kind: string} $asked */
        $asked = json_decode($request->text(), associative: true, flags: JSON_THROW_ON_ERROR);

        if ($asked['protocol'] !== Protocol::VERSION) {
            return NotAccelerated::because(sprintf('The fake speaks protocol %d.', Protocol::VERSION));
        }

        $keys = $asked['kind'] === Kind::UnitKeys->value ? $this->unitKeys($request) : $this->entryKeys($request);

        return Answer::ofText((string) json_encode(['protocol' => Protocol::VERSION, 'keys' => $keys]));
    }

    /** @return list<string> */
    private function entryKeys(Request $request): array
    {
        /** @var array{format: string, base: string, paths: list<string>, digests: list<string>, edges: list<list<int>>, always: list<int>, entries: list<list<int>>} $asked */
        $asked = json_decode($request->text(), associative: true, flags: JSON_THROW_ON_ERROR);

        return array_map(
            fn(array $starts): string => $this->keyOf($asked, [...$starts, ...$asked['always']]),
            $asked['entries'],
        );
    }

    /** @return list<string> */
    private function unitKeys(Request $request): array
    {
        /** @var array{format: string, base: string, tests: list<string>, sets: list<list<int>>, units: list<array{path: string, judgedBy: string, read: string, lines: list<array{int, int}>}>} $asked */
        $asked = json_decode($request->text(), associative: true, flags: JSON_THROW_ON_ERROR);
        $sets = array_map(static function (array $set) use ($asked): string {
            $ids = array_map(static fn(int $at): string => $asked['tests'][$at], $set);
            sort($ids, SORT_STRING);

            return hash('sha256', ContentKeys::framed(sprintf('%d', count($ids)), ...$ids));
        }, $asked['sets']);

        return array_map(static function (array $unit) use ($asked, $sets): string {
            $fields = [$asked['format'], $asked['base'], $unit['read'], 'unit', $unit['path'], $unit['judgedBy']];
            $fields[] = sprintf('%d', count($unit['lines']));

            foreach ($unit['lines'] as [$line, $set]) {
                array_push($fields, sprintf('%d', $line), $sets[$set]);
            }

            return hash('sha256', ContentKeys::framed(...$fields));
        }, $asked['units']);
    }

    /**
     * @param array{format: string, base: string, paths: list<string>, digests: list<string>, edges: list<list<int>>} $asked
     * @param list<int> $starts
     */
    private function keyOf(array $asked, array $starts): string
    {
        $queue = $this->walked($asked['edges'], $starts);
        $digests = [];
        $read = [];

        foreach ($queue as $place) {
            $read[] = $asked['paths'][$place];
            $digests[$asked['paths'][$place]] = $asked['digests'][$place];
        }

        sort($read);
        $context = hash_init('sha256');
        hash_update($context, ContentKeys::framed($asked['format'], $asked['base'], sprintf('%d', count($read))));

        foreach ($read as $path) {
            hash_update($context, ContentKeys::framed($path, $digests[$path]));
        }

        return hash_final($context);
    }

    /**
     * @param  list<list<int>> $edges
     * @param  list<int>       $starts
     * @return list<int>
     */
    private function walked(array $edges, array $starts): array
    {
        $reached = [];

        foreach ($starts as $start) {
            $reached[$start] = $start;
        }

        $queue = array_values($reached);

        $at = 0;

        while (array_key_exists($at, $queue)) {
            foreach ($edges[$queue[$at]] as $next) {
                $queue = array_key_exists($next, $reached) ? $queue : [...$queue, $next];
                $reached[$next] = $next;
            }

            $at++;
        }

        return $queue;
    }
}
