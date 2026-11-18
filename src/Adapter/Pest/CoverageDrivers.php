<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function dirname;
use function getenv;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;
use NightWorksIO\MutationGate\Core\Runner\IniScan;

/**
 * The coverage drivers a mutation run and every process it starts run with
 * (ADR-0147, ADR-0183): pcov off under `pest.pcovOff`, by an ini file in a
 * directory PHP scans after those it scans already, unless the run's streams
 * record lines with pcov; and Xdebug's mode off under `pest.xdebugOff`. A
 * mutation run reads no coverage but what its streams record. pcov reads no
 * variable of its own, and `pcov.enabled` can be set only as PHP starts, so
 * the scanned file is what reaches every process a run starts by its script.
 *
 * @internal
 */
final readonly class CoverageDrivers
{
    /** The ini file that turns pcov off, in a directory of its own in the workspace. */
    public const string INI = 'pcov-off/pcov.ini';

    /** What the ini file holds. */
    private const string PCOV_OFF = "pcov.enabled=0\n";

    private const string XDEBUG_OFF = Xdebug::OFF;

    /** @param list<string> $kept the globs of the files whose tests keep pcov on, as `pest.pcovKept` lists them */
    private function __construct(private string $pcovOff, private bool $xdebugOff, private array $kept = [])
    {
    }

    /**
     * What a mutation run sets, where its streams record lines with pcov or
     * not, its ini file written in the project; pcov as PHP is configured
     * where the file cannot be written.
     */
    public static function of(Patching $patching, bool $recordsWithPcov, Project $project): self
    {
        $ini = ! $recordsWithPcov && $patching->steps()->on(Toggle::PestPcovOff)
            ? $project->written(self::INI, self::PCOV_OFF)
            : '';

        return new self(
            is_string($ini) && $ini !== '' ? dirname($ini) : '',
            $patching->steps()->on(Toggle::PestXdebugOff),
            $patching->steps()->pcovKept(),
        );
    }

    /** Whether it sets nothing. */
    public function isNone(): bool
    {
        return $this->pcovOff === '' && ! $this->xdebugOff;
    }

    /**
     * A mutation run's command, its processes started with these drivers:
     * Pest's own mutation process keeps pcov on, as it refuses to mutate
     * without a coverage driver, and is told the directory each process it
     * starts adds to what it scans, and the globs whose tests keep pcov on
     * (see PcovTests).
     */
    public function mutating(Command $command): Command
    {
        return $command->with([
            ...$this->pcovOff === '' ? [] : [GateVariable::PcovOff->value => $this->pcovOff],
            ...$this->pcovOff === '' || $this->kept === []
                ? []
                : [GateVariable::PcovKept->value => implode("\n", $this->kept)],
            ...$this->xdebugOff ? [Xdebug::MODE_VARIABLE => self::XDEBUG_OFF] : [],
        ]);
    }

    /**
     * The command, its PHP processes started with these drivers: the ini
     * file's directory scanned after every directory the command scans
     * already, what it scanned before kept so the processes the gate starts
     * from there scan that again; pcov left on where the command's tests
     * read the driver (see PcovTests).
     */
    public function onto(Command $command): Command
    {
        $inherited = [...getenv(), ...array_filter($command->environment(), is_string(...))];

        return $command->with([
            ...$this->pcovOff === '' || PcovTests::keeps($command) ? [] : IniScan::adding($this->pcovOff, $inherited),
            ...$this->xdebugOff ? [Xdebug::MODE_VARIABLE => self::XDEBUG_OFF] : [],
        ]);
    }
}
