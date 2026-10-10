<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function sprintf;

/**
 * Where a killer stood in the order a mutant's own process started its
 * tests, as a killer line carries it: its position, from one, and the
 * digest of that order up to it (see OrderDigest), where it is the test
 * that process started last; and, always, the process, so the lines of two
 * runs on one mutated copy are told apart.
 */
final readonly class Placed
{
    /** How a killer file writes it: the process, the position and the digest, a mark for each it lacks. */
    private const string WRITTEN = '%d %s %s';

    /** What a killer file writes for a position or a digest it does not have. */
    private const string NONE = '-';

    /** What a killer file's text of it reads as: the process, a position or the mark, and a digest or the mark. */
    private const string READ = '/\A(\d+) (\d+|-) (\S+)\z/';

    private function __construct(private int $run, private int|NotGiven $at, private string|NotGiven $order)
    {
    }

    /** A killer at this position, with the digest of the order up to it, in the process with this id. */
    public static function at(int $position, string $order, int $run): self
    {
        return new self($run, $position, $order);
    }

    /** A killer the process with this id never started as a test of its own, as a class whose set-up failed. */
    public static function unplaced(int $run): self
    {
        return new self($run, NotGiven::value(), NotGiven::value());
    }

    /**
     * Where a killer stood, as a killer file's text of it says; or nothing,
     * where the text is not one {@see written()} writes.
     */
    public static function read(string $text): self|NotGiven
    {
        if (preg_match(self::READ, $text, $found) !== 1) {
            return NotGiven::value();
        }

        [, $run, $at, $order] = $found;

        return match (true) {
            $at === self::NONE && $order === self::NONE => self::unplaced((int) $run),
            $at !== self::NONE && $order !== self::NONE => self::at((int) $at, $order, (int) $run),
            default => NotGiven::value(),
        };
    }

    /** How a killer file writes it, on the killer's line (see KillerFile). */
    public function written(): string
    {
        return sprintf(
            self::WRITTEN,
            $this->run,
            $this->at instanceof NotGiven ? self::NONE : sprintf('%d', $this->at),
            $this->order instanceof NotGiven ? self::NONE : $this->order,
        );
    }

    /**
     * The fields a killer line carries of it.
     *
     * @return array<string, int|string>
     */
    public function fields(): array
    {
        return [
            ...($this->at instanceof NotGiven ? [] : [RecordField::At->value => $this->at]),
            ...($this->order instanceof NotGiven ? [] : [RecordField::Order->value => $this->order]),
            RecordField::Run->value => $this->run,
        ];
    }
}
