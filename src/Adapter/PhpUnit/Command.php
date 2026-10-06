<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_values;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Runner\Uncapped;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A program the adapter runs on the PHP that runs the gate: its arguments,
 * the variables it is told, those it never sees, its deadline, and the
 * directory of the memory cap its PHP processes scan, where it has one.
 */
final readonly class Command
{
    /**
     * The shell, and its script, that run a program with its standard output
     * and error written to two files, which the two arguments after the
     * script's own name name.
     */
    private const array WRITING = ['/bin/sh', '-c', 'out=$1 err=$2; shift 2; exec "$@" >"$out" 2>"$err"', 'sh'];

    /**
     * @param list<string>          $arguments after the PHP binary
     * @param array<string, string> $told      the variables it is set
     * @param list<string>          $writing   the shell that writes its output to files, and its arguments; none
     */
    private function __construct(
        private array $arguments,
        private array $told,
        private Withheld $withheld,
        private Seconds|Unlimited $deadline,
        private DiskPath|Uncapped $scanned,
        private array $writing = [],
    ) {
    }

    public static function php(string ...$arguments): self
    {
        return new self(array_values($arguments), [], Withheld::standard(), Unlimited::time(), Uncapped::Memory);
    }

    public function telling(Variable $variable, string $value): self
    {
        return clone($this, ['told' => [...$this->told, $variable->value => $value]]);
    }

    public function withholding(Withheld $withheld): self
    {
        return clone($this, ['withheld' => $this->withheld->and($withheld)]);
    }

    public function within(Seconds|Unlimited $deadline): self
    {
        return clone($this, ['deadline' => $deadline]);
    }

    /**
     * This command, its PHP processes scanning the memory cap's directory
     * too, after those they scan already (ADR-0004, decision 9).
     */
    public function scanning(DiskPath $directory): self
    {
        return clone($this, ['scanned' => $directory]);
    }

    /**
     * This command, its PHP's standard output and error written to these
     * files from its start, so every process it forks writes there too.
     */
    public function writingTo(string $out, string $err): self
    {
        return clone($this, ['writing' => [...self::WRITING, $out, $err]]);
    }

    /** @return list<string> the PHP binary, then the arguments: after the shell writing its output, where one does */
    public function arguments(): array
    {
        return [...$this->writing, PHP_BINARY, ...$this->arguments];
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->told;
    }

    public function withheld(): Withheld
    {
        return $this->withheld;
    }

    public function deadline(): Seconds|Unlimited
    {
        return $this->deadline;
    }

    /** The memory cap's directory its PHP processes scan too; none where it is uncapped. */
    public function scanned(): DiskPath|Uncapped
    {
        return $this->scanned;
    }
}
