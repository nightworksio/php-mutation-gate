<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_count_values;
use function array_filter;
use function array_key_exists;
use function array_key_last;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function is_file;
use function json_validate;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordField;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * What the plugin wrote of one Pest run: every mutant it planned, and whether
 * it wrote all of them, the status each ended with, how long each ran, what
 * each mutant's own process recorded (see OwnRun), how long the opening run
 * took, and whether the run reached its end. A line cut short, as a run
 * stopped while it wrote leaves its last, is not a record; any other line
 * that is not one, and a whole record that lacks a field its event carries
 * or holds a line no file has, refuses the file.
 *
 * It is read once, a line at a time, and changes no more once read.
 */
final class Records
{
    private const string MISSING
        = 'Pest wrote no results to %s. Is pestphp/pest-plugin allowed to run in composer.json?';

    private const string MALFORMED = 'Line %d of %s is not a record the gate reads: %s';

    /** How a line that is not a record is named. */
    private const string LINE = 'the line';

    /** How a record is named. */
    private const string RECORD = 'the record';

    /** @var list<PlannedMutant> in the order the plugin wrote them, which is the order it writes them finished */
    private array $planned = [];

    /** @var array<string, list<PestStatus>> each status Pest decided, by native id, in the order it decided them */
    private array $outcomes = [];

    /** @var array<string, list<float>> by native id, one for each mutant that shares it, in order */
    private array $durations = [];

    /** @var array<string, list<PestStatus>> the final status, by native id, one for each mutant that shares it */
    private array $finished = [];

    /** @var array<string, list<string>> the tests that errored, by the mutated copy they ran on */
    private array $errored = [];

    /** @var array<string, list<string>> the tests that failed or errored, in order, by the mutated copy they ran on */
    private array $killers = [];

    /** @var array<string, MemoryCap> the memory limit a mutant's own process ran out of, by the copy it ran on */
    private array $exhausted = [];

    /** @var array<string, list<string>> the test files each narrowed own run loaded, by the mutated copy it ran on */
    private array $narrowed = [];

    private Seconds|Unmeasured $opening;

    private bool $made = false;

    private bool $ended = false;

    private function __construct()
    {
        $this->opening = Unmeasured::duration();
    }

    public static function in(string $file): self|CannotJudge
    {
        if (! is_file($file)) {
            return CannotJudge::because(sprintf(self::MISSING, $file));
        }

        $records = new self();
        $lines = explode("\n", sprintf('%s', file_get_contents($file)));
        $last = array_key_last($lines);

        foreach ($lines as $index => $line) {
            try {
                $records->read($line, last: $index === $last);
            } catch (NotInShape $refused) {
                return CannotJudge::because(sprintf(self::MALFORMED, $index + 1, $file, $refused->getMessage()));
            }
        }

        return $records;
    }

    /**
     * Every planned mutant, ordered by file and then by the line it starts on.
     *
     * @return list<PlannedMutant>
     */
    public function planned(): array
    {
        return PlannedMutant::inOrder(PlannedMutant::numbered($this->planned));
    }

    /**
     * The status a mutant ended with, as Pest names it, or the last it
     * reported while it ran; none for one Pest never ran.
     */
    public function statusOf(PlannedMutant $mutant): PestStatus
    {
        return $this->nth($this->finished, $mutant, $this->nth($this->outcomes, $mutant, PestStatus::None));
    }

    /** How long a mutant ran; one Pest never started ran for no time it measured. */
    public function durationOf(PlannedMutant $mutant): Seconds|Unmeasured
    {
        $seconds = $this->nth($this->durations, $mutant, 0.0);

        return $seconds > 0.0 ? Seconds::of($seconds) : Unmeasured::duration();
    }

    /** What the plugin recorded of a mutant's own process. */
    public function runOf(PlannedMutant $mutant): OwnRun
    {
        $mutated = $mutant->mutated()->value();

        return OwnRun::of(
            array_key_exists($mutated, $this->killers) ? $this->killers[$mutated] : [],
            array_key_exists($mutated, $this->errored) ? $this->errored[$mutated] : [],
            array_key_exists($mutated, $this->narrowed) ? $this->narrowed[$mutated] : [],
            array_key_exists($mutated, $this->exhausted) ? $this->exhausted[$mutated] : NotGiven::value(),
        );
    }

    /** The seconds Pest allowed each mutant, from the opening run's. */
    public function limit(): Seconds|Unmeasured
    {
        return $this->opening instanceof Seconds ? PestTimeLimit::of($this->opening) : $this->opening;
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
        $finished = array_merge(...array_values($this->finished));
        $counted = $this->ended && $this->finishedAsPlanned();

        foreach (PestStatus::cases() as $status) {
            $ended = array_filter($finished, static fn(PestStatus $final): bool => $final === $status);
            $counted = $counted && $summary->count($status) === count($ended);
        }

        return $counted;
    }

    /**
     * Reads one line: the last may be cut short, or empty after the file's
     * final newline, and is then no record; any other must be one.
     *
     * @throws NotInShape
     */
    private function read(string $line, bool $last): void
    {
        if (! json_validate($line)) {
            if (! $last) {
                throw NotInShape::at(self::LINE, 'JSON: only the last line can be cut short');
            }

            return;
        }

        $record = Node::decode($line, named: self::RECORD);
        $event = $record->field(RecordField::Event->value);

        match (RecordEvent::tryFrom($event->text())) {
            RecordEvent::Planned => $this->withPlanned($record),
            RecordEvent::Made => $this->withMade($record),
            RecordEvent::Outcome => $this->withOutcome($record),
            RecordEvent::Finished => $this->withFinished($record),
            RecordEvent::Killed, RecordEvent::Errored => $this->withKiller($record, RecordEvent::from($event->text())),
            RecordEvent::Exhausted => $this->exhausted[$record->field(RecordField::Mutated->value)->text()]
                = WrittenBytes::read($record->field(RecordField::Bytes->value)),
            RecordEvent::Narrowed => $this->withNarrowed($record),
            RecordEvent::End => $this->ended = true,
            null => throw NotInShape::at($event->at(), 'an event the plugin writes'),
        };
    }

    /** @throws NotInShape */
    private function withPlanned(Node $record): void
    {
        $id = $record->field(RecordField::Id->value)->text();
        $start = $record->field(RecordField::Start->value);
        $end = $record->field(RecordField::End->value);

        if ($start->integer() < 1) {
            throw NotInShape::at($start->at(), 'a line of a file, which counts from 1');
        }

        if ($end->integer() < $start->integer()) {
            throw NotInShape::at($end->at(), 'a line at or after the one the mutant starts on');
        }

        $this->planned[] = PlannedMutant::of(
            $id,
            DiskPath::of($record->field(RecordField::File->value)->text()),
            Line::of($start->integer()),
            Line::of($end->integer()),
            $record->field(RecordField::Mutator->value)->text(),
            $record->field(RecordField::Diff->value)->text(),
            DiskPath::of($record->field(RecordField::Mutated->value)->text()),
        );
    }

    /**
     * What was recorded of a mutant among those that share its id, or the
     * given where none was. Pest writes `finished` in the order it planned
     * them, so the first such record is the first such mutant's. It writes
     * `outcome` as each ends, so among mutants that share an id the pairing
     * is arbitrary, and harmless: they leave the same source.
     *
     * @template T of PestStatus|float
     *
     * @param  array<string, list<T>> $byId
     * @param  T                      $none
     * @return T
     */
    private function nth(array $byId, PlannedMutant $mutant, PestStatus|float $none): PestStatus|float
    {
        $sharing = array_key_exists($mutant->id(), $byId) ? $byId[$mutant->id()] : [];

        return array_key_exists($mutant->occurrence(), $sharing) ? $sharing[$mutant->occurrence()] : $none;
    }

    /**
     * Whether each planned id finished once for every mutant Pest gave it,
     * and no other id did, in the order Pest planned them, which is the order
     * it writes them finished.
     */
    private function finishedAsPlanned(): bool
    {
        $ids = array_map(static fn(PlannedMutant $mutant): string => $mutant->id(), $this->planned);

        return array_map(count(...), $this->finished) === array_count_values($ids);
    }

    /** @throws NotInShape */
    private function withMade(Node $record): void
    {
        $opening = $record->field(RecordField::Opening->value);
        $this->opening = $opening->isPresent() ? Seconds::of($opening->number()) : Unmeasured::duration();
        $this->made = $record->field(RecordField::Count->value)->integer() === count($this->planned);
    }

    /** @throws NotInShape */
    private function withOutcome(Node $record): void
    {
        $this->outcomes[$record->field(RecordField::Id->value)->text()][] = $this->statusIn($record);
    }

    /** @throws NotInShape */
    private function withFinished(Node $record): void
    {
        $id = $record->field(RecordField::Id->value)->text();
        $this->finished[$id][] = $this->statusIn($record);
        $this->durations[$id][] = $record->field(RecordField::Duration->value)->number();
    }

    /** @throws NotInShape */
    private function withKiller(Node $record, RecordEvent $event): void
    {
        $mutated = $record->field(RecordField::Mutated->value)->text();
        $test = $record->field(RecordField::Test->value)->text();
        $this->killers[$mutated][] = $test;

        if ($event === RecordEvent::Errored) {
            $this->errored[$mutated][] = $test;
        }
    }

    /** @throws NotInShape */
    private function withNarrowed(Node $record): void
    {
        $mutated = $record->field(RecordField::Mutated->value)->text();
        $this->narrowed[$mutated] = array_map(
            static fn(Node $file): string => $file->text(),
            $record->field(RecordField::Files->value)->items(),
        );
    }

    /** @throws NotInShape */
    private function statusIn(Node $record): PestStatus
    {
        $status = $record->field(RecordField::Status->value);

        return PestStatus::tryFrom($status->text()) ?? throw NotInShape::at($status->at(), 'a status Pest records');
    }
}
