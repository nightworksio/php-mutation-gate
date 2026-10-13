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
use function is_float;
use function json_validate;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordField;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
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

    /** What a line cut short anywhere but last is told by, with as much of it as the message shows. */
    private const string CUT_SHORT = 'JSON: only the last line can be cut short, and it reads `%s`';

    /** How many characters of a line cut short a message shows. */
    private const int SHOWN = 160;

    /** How a line that is not a record is named. */
    private const string LINE = 'the line';

    /** How a record is named. */
    private const string RECORD = 'the record';

    /** @var list<PlannedMutant> in the order the plugin wrote them, which is the order it writes them finished */
    private array $planned = [];

    /** @var list<PlannedMutant> those kept out of Pest's run, each a planned one's twin, in the order Pest made them */
    private array $twins = [];

    /** @var array<string, list<PestStatus>> each status Pest decided, by native id, in the order it decided them */
    private array $outcomes = [];

    /** @var array<string, list<float>> by native id, one for each mutant that shares it, in order */
    private array $durations = [];

    /** @var array<string, list<PestStatus>> the final status, by native id, one for each mutant that shares it */
    private array $finished = [];

    /** What each mutant's own process recorded, by the mutated copy it ran on. */
    private readonly OwnRuns $runs;

    private Seconds|Unmeasured $opening;

    private bool $made = false;

    private bool $ended = false;

    private function __construct()
    {
        $this->opening = Unmeasured::duration();
        $this->runs = new OwnRuns();
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
     * Every planned mutant and every twin, ordered by file and then by the
     * line it starts on.
     *
     * @return list<PlannedMutant>
     */
    public function planned(): array
    {
        return PlannedMutant::inOrder(PlannedMutant::numbered([...$this->planned, ...$this->twins]));
    }

    /**
     * The status a mutant ended with, as Pest names it, or the last it
     * reported while it ran; none for one Pest never ran. A twin's is the
     * status of the mutant whose run judges it.
     */
    public function statusOf(PlannedMutant $mutant): PestStatus
    {
        $judged = $mutant->isTwin() ? $mutant->judgedBy(PlannedMutant::numbered($this->planned)) : $mutant;

        return $this->nth($this->finished, $judged, $this->nth($this->outcomes, $judged, PestStatus::None));
    }

    /**
     * How long a mutant ran; one Pest never started ran for no time it
     * measured, and a twin, which no run ran, for none.
     */
    public function durationOf(PlannedMutant $mutant): Seconds|Unmeasured
    {
        $seconds = $this->nth($this->durations, $mutant, Unmeasured::duration());

        return match (true) {
            $mutant->isTwin() => Seconds::of(0.0),
            is_float($seconds) && $seconds > 0.0 => Seconds::of($seconds),
            default => Unmeasured::duration(),
        };
    }

    /** What the plugin recorded of a mutant's own process. */
    public function runOf(PlannedMutant $mutant): OwnRun
    {
        return $this->runs->of($mutant->mutated()->value());
    }

    /**
     * Whether another planned mutant shares this one's mutated copy, so that
     * what their own runs recorded cannot be told apart.
     */
    public function sharesItsCopy(PlannedMutant $mutant): bool
    {
        $copy = $mutant->mutated()->value();
        $sharing = array_filter(
            $this->planned,
            static fn(PlannedMutant $one): bool => $one->mutated()->value() === $copy,
        );

        return count($sharing) > 1;
    }

    /** The silence limit a patched run stopped the mutant's own run at; none where it did not. */
    public function silenceOf(PlannedMutant $mutant): Seconds|NotGiven
    {
        return $this->runs->silenceOf($mutant->mutated()->value());
    }

    /**
     * The seconds Pest allowed a mutant: those a patched run recorded for it
     * (see MutantTime), or else what Pest allows every mutant, from the
     * opening run's.
     */
    public function limitOf(PlannedMutant $mutant): Seconds|Unmeasured
    {
        $recorded = $this->runs->limitOf($mutant->mutated()->value());

        return match (true) {
            $recorded instanceof Seconds => $recorded,
            $this->opening instanceof Seconds => PestTimeLimit::of($this->opening),
            default => $this->opening,
        };
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
                $shown = Fit::line(Fit::verbatim($line), self::SHOWN);

                throw NotInShape::at(self::LINE, sprintf(self::CUT_SHORT, $shown));
            }

            return;
        }

        $record = Node::decode($line, named: self::RECORD);
        $event = $record->field(RecordField::Event->value);

        match (RecordEvent::tryFrom($event->text())) {
            RecordEvent::Planned, RecordEvent::Twin => $this->withPlanned($record),
            RecordEvent::Made => $this->withMade($record),
            RecordEvent::Outcome => $this->withOutcome($record),
            RecordEvent::Finished => $this->withFinished($record),
            RecordEvent::Killed => $this->runs->killed($record),
            RecordEvent::Errored => $this->runs->errored($record),
            RecordEvent::Exhausted => $this->runs->exhausted($record),
            RecordEvent::Fatal => $this->runs->fatal($record),
            RecordEvent::Preloaded => $this->runs->preloaded($record),
            RecordEvent::Narrowed => $this->runs->narrowed($record),
            RecordEvent::Limited => $this->runs->limited($record),
            RecordEvent::Silent => $this->runs->silent($record),
            RecordEvent::Ran => $this->runs->ran($record),
            RecordEvent::Arguments => $this->runs->startedWith($record),
            RecordEvent::Stopped => throw NotInShape::at($event->at(), 'an event of a mutation run, not a replay\'s'),
            RecordEvent::Ended => $this->runs->ended($record),
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

        $planned = PlannedMutant::of(
            $id,
            DiskPath::of($record->field(RecordField::File->value)->text()),
            Line::of($start->integer()),
            Line::of($end->integer()),
            $record->field(RecordField::Mutator->value)->text(),
            $record->field(RecordField::Diff->value)->text(),
            DiskPath::of($record->field(RecordField::Mutated->value)->text()),
        );

        if ($record->field(RecordField::Event->value)->text() === RecordEvent::Twin->value) {
            $this->twins[] = $planned->asTwin();

            return;
        }

        $this->planned[] = $planned;
    }

    /**
     * What was recorded of a mutant among those that share its id, or the
     * given where none was. Pest writes `finished` in the order it planned
     * them, so the first such record is the first such mutant's. It writes
     * `outcome` as each ends, so among mutants that share an id the pairing
     * is arbitrary, and harmless: they leave the same source.
     *
     * @template T of PestStatus|float
     * @template N of PestStatus|Unmeasured
     *
     * @param  array<string, list<T>> $byId
     * @param  N                      $none
     * @return T|N
     */
    private function nth(array $byId, PlannedMutant $mutant, PestStatus|Unmeasured $none): PestStatus|float|Unmeasured
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
    private function statusIn(Node $record): PestStatus
    {
        $status = $record->field(RecordField::Status->value);

        return PestStatus::tryFrom($status->text()) ?? throw NotInShape::at($status->at(), 'a status Pest records');
    }
}
