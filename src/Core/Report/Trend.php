<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function array_slice;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Traversable;

/**
 * `trend.json`: one entry per verdict on the default branch, with its commit,
 * its time, the project's score and each tree's, and the run's runner time
 * and the time a full one-job run would take where the run knew them
 * (ADR-0017, decision 13), keeping the newest 500. A set with nothing to
 * mutate has no score in its entry. Reading drops an entry that is not in
 * shape rather than repair it.
 *
 * @phpstan-type Entry array{
 *     commit: string,
 *     time: string,
 *     score?: float,
 *     trees: array<string, float>,
 *     runnerSeconds?: float,
 *     fullRunSeconds?: float,
 * }
 */
final readonly class Trend
{
    private const int FORMAT = 1;

    /** How many entries it keeps, the newest. */
    private const int KEPT = 500;

    /**
     * @param list<Entry> $runs oldest first
     */
    private function __construct(private array $runs)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** A trend as `trend.json` holds it; text that is not one reads as none. */
    public static function decode(string $json): self
    {
        try {
            $items = Node::decode($json)->field('runs')->items();
        } catch (NotInShape) {
            return self::none();
        }

        $runs = [];

        foreach ($items as $item) {
            try {
                $runs[] = self::read($item);
            } catch (NotInShape) {
                continue;
            }
        }

        return new self($runs);
    }

    /** This trend, and a verdict's entry, keeping the newest. */
    public function with(Verdict $verdict, Revision $commit, Instant $time): self
    {
        $score = Overview::of($verdict)->score();
        $trees = [];

        foreach ($verdict->trees() as $tree) {
            $treeScore = $tree->score();

            if ($treeScore instanceof Score) {
                $trees[$tree->tree()->path()->value()] = $treeScore->percent();
            }
        }

        $timings = $verdict->account()->timings();
        $savings = $verdict->account()->savings();
        $run = [
            'commit' => $commit->name(),
            'time' => $time->value(),
            ...$score instanceof Score ? ['score' => $score->percent()] : [],
            'trees' => $trees,
            ...$timings instanceof RunTimings ? ['runnerSeconds' => $timings->spent()->runner()->seconds()] : [],
            ...$savings instanceof Savings ? ['fullRunSeconds' => $savings->fullRun()->seconds()] : [],
        ];
        $runs = [...$this->runs, $run];

        return new self(array_slice($runs, count($runs) > self::KEPT ? count($runs) - self::KEPT : 0));
    }

    public function json(): string
    {
        return Json::encode(['format' => self::FORMAT, 'runs' => $this->runs]);
    }

    /**
     * The project's score of every entry that has one, oldest first.
     *
     * @return Traversable<int, float>
     */
    public function scores(): Traversable
    {
        foreach ($this->runs as $run) {
            if (array_key_exists('score', $run)) {
                yield $run['score'];
            }
        }
    }

    /**
     * What the runs since this instant saved together: each one's full one-job
     * run less its runner time, never below nothing; no history where no such
     * run knew both.
     */
    public function savedSince(Instant $since): Seconds|NoHistory
    {
        $saved = 0.0;
        $known = false;

        foreach ($this->runs as $run) {
            $counted = $run['time'] >= $since->value()
                && array_key_exists('runnerSeconds', $run)
                && array_key_exists('fullRunSeconds', $run);
            $saved += $counted ? max(0.0, $run['fullRunSeconds'] - $run['runnerSeconds']) : 0.0;
            $known = $known || $counted;
        }

        return $known ? Seconds::of($saved) : NoHistory::yet();
    }

    /**
     * @return Entry
     *
     * @throws NotInShape
     */
    private static function read(Node $item): array
    {
        $trees = [];

        foreach ($item->field('trees')->entries() as $path => $score) {
            $trees[$path] = $score->number();
        }

        $score = $item->field('score');
        $runner = $item->field('runnerSeconds');
        $fullRun = $item->field('fullRunSeconds');

        return [
            'commit' => $item->field('commit')->text(),
            'time' => $item->field('time')->text(),
            ...$score->isPresent() ? ['score' => $score->number()] : [],
            'trees' => $trees,
            ...$runner->isPresent() ? ['runnerSeconds' => $runner->number()] : [],
            ...$fullRun->isPresent() ? ['fullRunSeconds' => $fullRun->number()] : [],
        ];
    }
}
