<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * The legs of the matrix jobs a CI job waits for, which a job that gathers what each leg left reads as its steps
 * (V2): each leg is named by its values, joined by `-`, in the order its matrix declares its dimensions, as
 * `<runner>-<resolution>`, from a matrix of dimensions alone, as the workflows write one.
 */
final readonly class Legs
{
    /**
     * The legs of every matrix job in a job's list of `needs`.
     *
     * @param  array<string, Node> $jobs the jobs of its workflow, by their ids
     * @return list<string>
     */
    public static function neededBy(Node $job, array $jobs): array
    {
        $legs = [];

        foreach (Lenient::items($job->field('needs')) as $need) {
            $needed = $jobs[Lenient::text($need)] ?? null;
            $legs = $needed instanceof Node ? [...$legs, ...self::of($needed)] : $legs;
        }

        return $legs;
    }

    /**
     * The legs of one job; none for a job with no matrix.
     *
     * @return list<string>
     */
    private static function of(Node $job): array
    {
        $legs = [];

        foreach (Lenient::entries($job->field('strategy')->field('matrix')) as $values) {
            $legs = self::crossed($legs, array_map(Lenient::text(...), Lenient::items($values)));
        }

        return $legs;
    }

    /**
     * Each leg so far with each value of one more dimension; the values alone for the first.
     *
     * @param  list<string> $legs
     * @param  list<string> $values
     * @return list<string>
     */
    private static function crossed(array $legs, array $values): array
    {
        $crossed = [];

        foreach ($legs === [] ? [''] : $legs as $leg) {
            foreach ($values as $value) {
                $crossed[] = $leg === '' ? $value : sprintf('%s-%s', $leg, $value);
            }
        }

        return $crossed;
    }
}
