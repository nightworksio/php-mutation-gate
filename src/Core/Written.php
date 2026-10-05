<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

use NightWorksIO\MutationGate\Core\Format\Fit;

use function sprintf;

/** Something was written, where it went, and what else the writer says of it. */
final readonly class Written
{
    /** Where a writer writes to print to the command's output. */
    public const string OUTPUT = 'php://output';

    /** Where a report printed on the command's output went, as its line says. */
    private const string CONSOLE = 'the console';

    private const string WROTE = 'Wrote %s.';

    private const string UNWRITTEN = '%s could not be written.';

    private const string WROTE_NOTING = 'Wrote %s. %s';

    private function __construct(private string $where, private string $note)
    {
    }

    public static function to(string $where): self
    {
        return new self($where, '');
    }

    /** Printed on the command's output. */
    public static function toTheConsole(): self
    {
        return new self(self::CONSOLE, '');
    }

    /** Written to there where a write answered with the bytes it wrote; why not where it answered `false`. */
    public static function attempted(string $where, int|false $wrote): self|CannotJudge
    {
        return $wrote === false ? self::failedAt($where) : new self($where, '');
    }

    /** Why nothing is written there: the write failed. */
    public static function failedAt(string $where): CannotJudge
    {
        return CannotJudge::because(sprintf(self::UNWRITTEN, $where));
    }

    /** Written to there, with a sentence the writer says of what it wrote. */
    public static function noting(string $where, string $note): self
    {
        return new self($where, $note);
    }

    /** That write, with this said of it before what its writer noted. */
    public static function sayingFirst(string $note, self $written): self
    {
        return new self($written->where, $written->note === '' ? $note : sprintf(Fit::JOINED, $note, $written->note));
    }

    public function where(): string
    {
        return $this->where;
    }

    /** The line a command prints for it. */
    public function said(): string
    {
        return $this->note === ''
            ? sprintf(self::WROTE, $this->where)
            : sprintf(self::WROTE_NOTING, $this->where, $this->note);
    }
}
