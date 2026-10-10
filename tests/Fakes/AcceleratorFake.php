<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function hash_final;
use function hash_init;
use function hash_update;
use function json_decode;
use function json_encode;

use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Turbo\Answer;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Protocol;
use NightWorksIO\MutationGate\Core\Turbo\Request;
use NightWorksIO\MutationGate\Port\Accelerator;

use function sort;
use function sprintf;

/**
 * An accelerator that answers entry keys in plain PHP, from the request
 * alone: each entry's starting files and what they name, walked breadth
 * first, sorted as PHP sorts, and hashed with the key's framing.
 */
final readonly class AcceleratorFake implements Accelerator
{
    public function answer(Request $request): Answer|NotAccelerated
    {
        /** @var array{protocol: int, kind: string, format: string, base: string, paths: list<string>, digests: list<string>, edges: list<list<int>>, always: list<int>, entries: list<list<int>>} $asked */
        $asked = json_decode($request->text(), associative: true, flags: JSON_THROW_ON_ERROR);

        if ($asked['protocol'] !== Protocol::VERSION) {
            return NotAccelerated::because(sprintf('The fake speaks protocol %d.', Protocol::VERSION));
        }

        $keys = array_map(
            fn(array $starts): string => $this->keyOf($asked, [...$starts, ...$asked['always']]),
            $asked['entries'],
        );

        return Answer::ofText((string) json_encode(['protocol' => Protocol::VERSION, 'keys' => $keys]));
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
