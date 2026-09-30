<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function is_file;
use function json_validate;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * What the plugin wrote of one Pest run: every mutant it planned, and whether
 * it wrote all of them, the status each ended with, how long each ran, the
 * tests that failed in each mutant's own process, how long the opening run
 * took, and whether the run reached its end. A line cut short, as a run
 * stopped while it wrote leaves its last, is not a record; a whole record
 * that lacks a field its event carries refuses the file.
 *
 * It is read once, a line at a time, and changes no more once read.
 */
final class Records
{
    private const string MISSING
        = 'Pest wrote no results to %s. Is pestphp/pest-plugin allowed to run in composer.json?';

    private const string MALFORMED = 'Line %d of %s is not a record the gate reads: %s';

    /** @var array<string, PlannedMutant> by native id */
    private array $planned = [];

    /** @var array<string, PestStatus> the latest status, by native id */
    private array $statuses = [];

    /** @var array<string, float> by native id */
    private array $durations = [];

    /** @var array<string, PestStatus> the final status, by native id */
    private array $finished = [];

    /** @var array<string, list<string>> the tests that failed, in order, by the mutated copy they ran on */
    private array $killers = [];

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

        foreach (explode("\n", sprintf('%s', file_get_contents($file))) as $index => $line) {
            try {
                $records->read($line);
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
        return PlannedMutant::inOrder(array_values($this->planned));
    }

    /** The status a mutant ended with, as Pest names it; none for one Pest never ran. */
    public function statusOf(string $id): PestStatus
    {
        return array_key_exists($id, $this->statuses) ? $this->statuses[$id] : PestStatus::None;
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
        $mutated = array_key_exists($id, $this->planned) ? $this->planned[$id]->mutated()->value() : '';
        $named = array_key_exists($mutated, $this->killers) ? $this->killers[$mutated] : [];

        return TestIds::of(...array_map(TestId::of(...), $named));
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
        $planned = count($this->planned);
        $counted = $this->ended
            && array_diff_key($this->planned, $this->finished) === []
            && count($this->finished) === $planned
            && $summary->total() === $planned;

        foreach (PestStatus::cases() as $status) {
            $finished = array_filter($this->finished, static fn(PestStatus $final): bool => $final === $status);
            $counted = $counted && $summary->count($status) === count($finished);
        }

        return $counted;
    }

    /** @throws NotInShape */
    private function read(string $line): void
    {
        if (! json_validate($line)) {
            return;
        }

        $record = Node::decode($line);
        $event = $record->field(RecordLine::EVENT);

        match (RecordEvent::tryFrom($event->text())) {
            RecordEvent::Planned => $this->withPlanned($record),
            RecordEvent::Made => $this->withMade($record),
            RecordEvent::Outcome => $this->statuses[$record->field(RecordLine::ID)->text()] = $this->statusIn($record),
            RecordEvent::Finished => $this->withFinished($record),
            RecordEvent::Killed => $this->withKiller($record),
            RecordEvent::End => $this->ended = true,
            null => throw NotInShape::at($event->at(), 'an event the plugin writes'),
        };
    }

    /** @throws NotInShape */
    private function withPlanned(Node $record): void
    {
        $id = $record->field(RecordLine::ID)->text();
        $this->planned[$id] = PlannedMutant::of(
            $id,
            DiskPath::of($record->field(RecordLine::FILE)->text()),
            Line::of($record->field(RecordLine::START)->integer()),
            Line::of($record->field(RecordLine::END)->integer()),
            $record->field(RecordLine::MUTATOR)->text(),
            $record->field(RecordLine::DIFF)->text(),
            DiskPath::of($record->field(RecordLine::MUTATED)->text()),
        );
    }

    /** @throws NotInShape */
    private function withMade(Node $record): void
    {
        $opening = $record->field(RecordLine::OPENING);
        $this->opening = $opening->isPresent() ? Seconds::of($opening->number()) : Unmeasured::duration();
        $this->made = $record->field(RecordLine::COUNT)->integer() === count($this->planned);
    }

    /** @throws NotInShape */
    private function withFinished(Node $record): void
    {
        $id = $record->field(RecordLine::ID)->text();
        $status = $this->statusIn($record);
        $this->statuses[$id] = $status;
        $this->finished[$id] = $status;
        $this->durations[$id] = $record->field(RecordLine::DURATION)->number();
    }

    /** @throws NotInShape */
    private function withKiller(Node $record): void
    {
        $this->killers[$record->field(RecordLine::MUTATED)->text()][] = $record->field(RecordLine::TEST)->text();
    }

    /** @throws NotInShape */
    private function statusIn(Node $record): PestStatus
    {
        $status = $record->field(RecordLine::STATUS);

        return PestStatus::tryFrom($status->text()) ?? throw NotInShape::at($status->at(), 'a status Pest records');
    }
}
