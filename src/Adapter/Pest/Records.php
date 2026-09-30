<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
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
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;
use function uasort;

/**
 * What the plugin wrote of one Pest run: every mutant it planned, the status
 * each ended with, how long each ran, whether the run reached its end, and
 * how long its opening run took.
 *
 * @phpstan-type Planned array{file: string, start: int, end: int, mutator: string, diff: string}
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
     */
    private function __construct(
        private array $planned,
        private array $statuses,
        private array $durations,
        private array $finished,
        private Seconds|Unmeasured $opening,
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

        $records = new self([], [], [], [], Unmeasured::duration(), ended: false);

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
        $planned = $this->planned;
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

    /** Whether the run reached its end, and every planned mutant's final status adds up to Pest's own summary. */
    public function addUpTo(Summary $summary): bool
    {
        $planned = count($this->planned);
        $counted = $this->ended && count($this->finished) === $planned && $summary->total() === $planned;

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
            'outcome' => $this->withOutcome($record),
            'finished' => $this->withFinished($record),
            'end' => $this->withEnd($record),
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
        ];

        return new self(
            [...$this->planned, $this->text($record, 'id') => $planned],
            $this->statuses,
            $this->durations,
            $this->finished,
            $this->opening,
            $this->ended,
        );
    }

    /** @param array<mixed> $record */
    private function withOutcome(array $record): self
    {
        return new self(
            $this->planned,
            [...$this->statuses, $this->text($record, 'id') => $this->text($record, 'status')],
            $this->durations,
            $this->finished,
            $this->opening,
            $this->ended,
        );
    }

    /** @param array<mixed> $record */
    private function withFinished(array $record): self
    {
        $id = $this->text($record, 'id');
        $status = $this->text($record, 'status');
        $durations = array_key_exists('duration', $record) && is_float($record['duration'])
            ? [...$this->durations, $id => $record['duration']]
            : $this->durations;

        return new self(
            $this->planned,
            [...$this->statuses, $id => $status],
            $durations,
            [...$this->finished, $id => $status],
            $this->opening,
            $this->ended,
        );
    }

    /** @param array<mixed> $record */
    private function withEnd(array $record): self
    {
        $opening = array_key_exists('opening', $record) && is_float($record['opening'])
            ? Seconds::of($record['opening'])
            : Unmeasured::duration();

        return new self($this->planned, $this->statuses, $this->durations, $this->finished, $opening, ended: true);
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
