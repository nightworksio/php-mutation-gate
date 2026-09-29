<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function count;
use function explode;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;
use function uasort;

/**
 * What the plugin wrote of one Pest run: every mutant it planned, the status
 * each ended with, how long each ran, and whether the run reached its end.
 *
 * @phpstan-type Planned array{file: string, start: int, end: int, mutator: string, diff: string}
 */
final readonly class Records
{
    /** The status of a mutant Pest never ran. */
    public const string NONE = 'none';

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
        private bool $ended,
    ) {
    }

    public static function in(string $file): self|CannotJudge
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        if (! is_string($text)) {
            return CannotJudge::because(sprintf(
                'Pest wrote no results to %s. Is pestphp/pest-plugin allowed to run in composer.json?',
                $file,
            ));
        }

        $records = new self([], [], [], [], ended: false);

        foreach (explode("\n", $text) as $line) {
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
            'end' => new self($this->planned, $this->statuses, $this->durations, $this->finished, ended: true),
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
            $this->ended,
        );
    }

    /** @param array<mixed> $record */
    private function withFinished(array $record): self
    {
        $id = $this->text($record, 'id');
        $status = $this->text($record, 'status');
        $duration = array_key_exists('duration', $record) && is_float($record['duration']) ? $record['duration'] : 0.0;

        return new self(
            $this->planned,
            [...$this->statuses, $id => $status],
            [...$this->durations, $id => $duration],
            [...$this->finished, $id => $status],
            $this->ended,
        );
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
