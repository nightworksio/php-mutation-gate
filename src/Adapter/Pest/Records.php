<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function array_map;
use function count;
use function explode;
use function file_get_contents;
use function floor;
use function is_array;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;
use function uasort;

/**
 * What the plugin wrote of one Pest run: every mutant it planned, and whether
 * it wrote all of them, the status each ended with, how long each ran, the
 * tests that failed in each mutant's own process, how long the opening run
 * took, and whether the run reached its end.
 *
 * @phpstan-type Planned array{file: string, start: int, end: int, mutator: string, diff: string, mutated: string}
 */
final readonly class Records
{
    /** The status of a mutant Pest never ran. */
    public const string NONE = 'none';

    /** The least Pest adds to the opening run's seconds to allow a mutant. */
    private const float LEAST_EXTRA = 5.0;

    /** The share of the opening run's seconds Pest adds where it is more. */
    private const float EXTRA_SHARE = 0.2;

    /** The statuses Pest's summary counts. */
    private const array STATUSES = ['untested', 'uncovered', self::NONE, 'timeout', 'tested'];

    /**
     * @param array<string, Planned> $planned   by native id
     * @param array<string, string>  $statuses  the latest status, by native id
     * @param array<string, float>   $durations by native id
     * @param array<string, string>  $finished  the final status, by native id
     * @param array<string, list<string>> $killers the tests that failed, in order, by the mutated copy they ran on
     */
    private function __construct(
        private array $planned,
        private array $statuses,
        private array $durations,
        private array $finished,
        private array $killers,
        private Seconds|Unmeasured $opening,
        private bool $made,
        private bool $ended,
    ) {
    }

    public static function in(string $file): self|CannotJudge
    {
        if (! is_file($file)) {
            return CannotJudge::because(sprintf(
                'Pest wrote no results to %s. Is pestphp/pest-plugin allowed to run in composer.json?',
                $file,
            ));
        }

        $records = new self([], [], [], [], [], Unmeasured::duration(), made: false, ended: false);

        foreach (explode("\n", sprintf('%s', file_get_contents($file))) as $line) {
            $records = $records->read($line);
        }

        return $records;
    }

    /**
     * Every planned mutant, by native id, ordered by file and then by the line it starts on.
     *
     * @return array<string, Planned>
     */
    public function planned(): array
    {
        return self::sorted($this->planned);
    }

    /**
     * Planned mutants ordered by file and then by the line each starts on,
     * each keeping its place among those that share both.
     *
     * @param  array<string, Planned> $planned
     * @return array<string, Planned>
     */
    public static function sorted(array $planned): array
    {
        uasort(
            $planned,
            static fn(array $one, array $two): int => $one['file'] === $two['file']
                ? $one['start'] <=> $two['start']
                : $one['file'] <=> $two['file'],
        );

        return $planned;
    }

    /** The status a mutant ended with, as Pest names it: tested, untested, uncovered, timeout or none. */
    public function statusOf(string $id): string
    {
        return array_key_exists($id, $this->statuses) ? $this->statuses[$id] : self::NONE;
    }

    /** How long a mutant ran; one Pest never started ran for no time it measured. */
    public function durationOf(string $id): Seconds|Unmeasured
    {
        $ran = array_key_exists($id, $this->durations) && $this->durations[$id] > 0.0;

        return $ran ? Seconds::of($this->durations[$id]) : Unmeasured::duration();
    }

    /**
     * The tests that failed in a mutant's own process, in the order they
     * failed: the first killed it. None where no test is known to have.
     */
    public function killersOf(string $id): TestIds
    {
        $mutated = array_key_exists($id, $this->planned) ? $this->planned[$id]['mutated'] : '';
        $named = array_key_exists($mutated, $this->killers) ? $this->killers[$mutated] : [];

        return TestIds::of(...array_map(TestId::of(...), $named));
    }

    /**
     * The seconds Pest allowed each mutant: the opening run's, and the larger
     * of five seconds and a fifth of them more, in whole seconds.
     */
    public function limit(): Seconds|Unmeasured
    {
        $opening = $this->opening;

        return $opening instanceof Seconds
            ? Seconds::of(floor($opening->seconds() + max(self::LEAST_EXTRA, $opening->seconds() * self::EXTRA_SHARE)))
            : Unmeasured::duration();
    }

    /** Whether Pest wrote every mutant it made, which a run stopped while it wrote them did not. */
    public function allMade(): bool
    {
        return $this->made;
    }

    /** Whether the run reached its end, whatever its exit code. */
    public function ended(): bool
    {
        return $this->ended;
    }

    /**
     * Whether the run reached its end, every planned mutant finished and no
     * other did, and their final statuses add up to Pest's own summary.
     */
    public function addUpTo(Summary $summary): bool
    {
        $planned = count($this->planned);
        $counted = $this->ended
            && array_diff_key($this->planned, $this->finished) === []
            && count($this->finished) === $planned
            && $summary->total() === $planned;

        foreach (self::STATUSES as $status) {
            $finished = array_filter($this->finished, static fn(string $final): bool => $final === $status);
            $counted = $counted && $summary->count($status) === count($finished);
        }

        return $counted;
    }

    private function read(string $line): self
    {
        $decoded = json_decode($line, associative: true);
        $record = is_array($decoded) ? $decoded : [];

        return match ($this->text($record, 'event')) {
            'planned' => $this->withPlanned($record),
            'made' => $this->withMade($record),
            'outcome' => clone($this, [
                'statuses' => [...$this->statuses, $this->text($record, 'id') => $this->text($record, 'status')],
            ]),
            'finished' => $this->withFinished($record),
            'killed' => $this->withKiller($record),
            'end' => clone($this, ['ended' => true]),
            default => $this,
        };
    }

    /** @param array<mixed> $record */
    private function withPlanned(array $record): self
    {
        $planned = [
            'file' => $this->text($record, 'file'),
            'start' => $this->number($record, 'start'),
            'end' => $this->number($record, 'end'),
            'mutator' => $this->text($record, 'mutator'),
            'diff' => $this->text($record, 'diff'),
            'mutated' => $this->text($record, 'mutated'),
        ];

        return clone($this, ['planned' => [...$this->planned, $this->text($record, 'id') => $planned]]);
    }

    /** @param array<mixed> $record */
    private function withMade(array $record): self
    {
        $opening = array_key_exists('opening', $record) && is_float($record['opening'])
            ? Seconds::of($record['opening'])
            : Unmeasured::duration();

        return clone($this, [
            'opening' => $opening,
            'made' => $this->number($record, 'count') === count($this->planned),
        ]);
    }

    /** @param array<mixed> $record */
    private function withFinished(array $record): self
    {
        $id = $this->text($record, 'id');
        $status = $this->text($record, 'status');
        $durations = array_key_exists('duration', $record) && is_float($record['duration'])
            ? [...$this->durations, $id => $record['duration']]
            : $this->durations;

        return clone($this, [
            'statuses' => [...$this->statuses, $id => $status],
            'durations' => $durations,
            'finished' => [...$this->finished, $id => $status],
        ]);
    }

    /** @param array<mixed> $record */
    private function withKiller(array $record): self
    {
        $mutated = $this->text($record, 'mutated');
        $test = $this->text($record, 'test');
        $named = array_key_exists($mutated, $this->killers) ? $this->killers[$mutated] : [];

        return $test === '' ? $this : clone($this, ['killers' => [...$this->killers, $mutated => [...$named, $test]]]);
    }

    /** @param array<mixed> $record */
    private function text(array $record, string $key): string
    {
        return array_key_exists($key, $record) && is_string($record[$key]) ? $record[$key] : '';
    }

    /** @param array<mixed> $record */
    private function number(array $record, string $key): int
    {
        return array_key_exists($key, $record) && is_int($record[$key]) ? $record[$key] : 0;
    }
}
