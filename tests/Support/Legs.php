<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use function explode;

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function preg_match;
use function sprintf;

/**
 * The legs of the matrix jobs a CI job waits for, which a job that gathers what each leg left reads as its steps
 * (V2): each leg is named by its values, joined by `-`, in the order its matrix declares its dimensions, then by each
 * word of its `RESOLUTIONS`, where a matrix job runs several legs one after the other, as `<runner>-<resolution>`,
 * from a matrix of dimensions alone, as the workflows write one. A dimension a run narrows by an expression,
 * `${{ fromJSON(<output> || '<list>') }}`, is read by the list it falls back to, which holds every value it can take;
 * one a called workflow reads from its input, `${{ fromJSON(inputs.<name>) }}`, by the list the caller's input falls
 * back to. A value that is a job of several runners, `{"name": ..., "runners": "<runner> <runner>"}`, is each runner.
 */
final readonly class Legs
{
    /** An expression's fallback list, `'[...]'` after its last `||`. */
    private const string FALLBACK = '/\|\|\s*\'(\[[^\']*\])\'\s*\)?\s*\}\}$/';

    /** A dimension a called workflow reads from one of its inputs. */
    private const string INPUT = '/^\$\{\{\s*fromJSON\(inputs\.([\w-]+)\)\s*\}\}$/';

    /**
     * The legs of every matrix job in a job's list of `needs`, and of every job of a workflow one of them calls.
     *
     * @param  array<string, Node>                  $jobs  the jobs of its workflow, by their ids
     * @param  Closure(string): array<string, Node> $calls the jobs of a workflow by its path, by their ids
     * @return list<string>
     */
    public static function neededBy(Node $job, array $jobs, Closure $calls): array
    {
        $legs = [];

        foreach (Lenient::items($job->field('needs')) as $need) {
            $needed = $jobs[Lenient::text($need)] ?? null;
            $legs = $needed instanceof Node ? [...$legs, ...self::called($needed, $calls)] : $legs;
        }

        return $legs;
    }

    /**
     * The legs of one job, or of each job of the workflow it calls, its inputs as it passes them.
     *
     * @param  Closure(string): array<string, Node> $calls
     * @return list<string>
     */
    private static function called(Node $job, Closure $calls): array
    {
        if (preg_match(Gates::CALLED, Lenient::text($job->field('uses')), $called) !== 1) {
            return self::of($job, []);
        }

        $inputs = [];

        foreach (Lenient::entries($job->field('with')) as $input => $value) {
            $inputs[sprintf('%s', $input)] = Lenient::text($value);
        }

        $legs = [];

        foreach ($calls($called[1]) as $inner) {
            $legs = [...$legs, ...self::of($inner, $inputs)];
        }

        return $legs;
    }

    /**
     * The legs of one job; none for a job with no matrix.
     *
     * @param  array<string, string> $inputs what the job's workflow was called with, by input
     * @return list<string>
     */
    private static function of(Node $job, array $inputs): array
    {
        $legs = [];

        foreach (Lenient::entries($job->field('strategy')->field('matrix')) as $values) {
            $legs = self::crossed($legs, self::values($values, $inputs));
        }

        $resolutions = Lenient::text($job->field('env')->field('RESOLUTIONS'));

        return $resolutions === '' || $legs === [] ? $legs : self::crossed($legs, explode(' ', $resolutions));
    }

    /**
     * The values of one dimension: its items, or the list an expression falls back to, each job of several runners
     * as its runners.
     *
     * @param  array<string, string> $inputs
     * @return list<string>
     */
    private static function values(Node $dimension, array $inputs): array
    {
        $text = Lenient::text($dimension);
        $text = preg_match(self::INPUT, $text, $input) === 1 ? $inputs[$input[1]] ?? '' : $text;
        $listed = preg_match(self::FALLBACK, $text, $fallback) === 1
            ? Node::decode($fallback[1])
            : $dimension;
        $values = [];

        foreach (Lenient::items($listed) as $item) {
            $runners = Lenient::text($item->field('runners'));
            $values = [...$values, ...$runners === '' ? [Lenient::text($item)] : explode(' ', $runners)];
        }

        return $values;
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
