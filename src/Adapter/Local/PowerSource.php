<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Local;

use function file_get_contents;
use function glob;
use function is_array;
use function is_readable;
use function is_string;

use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\Local\System;

use function preg_match;
use function sprintf;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function trim;

/**
 * Where the machine draws its power from, read when asked (ADR-0120): on
 * macOS from `pmset`, on Linux from `/sys/class/power_supply` and the ACPI
 * platform profile, on Windows from PowerShell's `Win32_Battery`. PHP has no
 * way to be told of a change without native code, so `watch` reads it at
 * the start of each round, and at each wait while it holds one. A source it
 * cannot read is taken as mains.
 *
 * @internal
 */
final readonly class PowerSource
{
    private const string PMSET = '/usr/bin/pmset';

    /** What `pmset -g ps` prints while the machine draws from its battery. */
    private const string ON_BATTERY = "~'Battery Power'~";

    /** The line `pmset -g` prints while Low Power Mode is on. */
    private const string LOW_POWER = '~^\s*lowpowermode\s+1\s*$~m';

    /** The supplies Linux lists, each a directory. */
    private const string SUPPLIES = '%s/class/power_supply/*';

    /** A file of a supply. */
    private const string SUPPLY_FILE = '%s/%s';

    /** The ACPI platform profile Linux sets for power saving. */
    private const string PROFILE = '%s/firmware/acpi/platform_profile';

    private const string LOW_POWER_PROFILE = 'low-power';

    private const string DISCHARGING = 'Discharging';

    private const string BATTERY = 'Battery';

    private const string POWERSHELL = 'powershell';

    /** What PowerShell prints of `Win32_Battery`: 1 where the battery discharges. */
    private const string BATTERY_STATUS
        = '(Get-CimInstance -ClassName Win32_Battery | Select-Object -First 1).BatteryStatus';

    private const string WINDOWS_DISCHARGING = '1';

    /** How long a program that reads the power source may take, in seconds. */
    private const float READ_WITHIN = 5.0;

    private function __construct(private System $system, private string $sys)
    {
    }

    /** This machine's. */
    public static function here(): self
    {
        return new self(System::here(), '/sys');
    }

    /** One on this system, its Linux supplies under this directory in place of `/sys`. */
    public static function on(System $system, string $sys): self
    {
        return new self($system, $sys);
    }

    public function now(): Power
    {
        return match ($this->system) {
            System::Darwin => self::macos(
                $this->printed([self::PMSET, '-g', 'ps']),
                $this->printed([self::PMSET, '-g']),
            ),
            System::Linux => $this->linux(),
            System::Windows => self::windows($this->printed(
                [self::POWERSHELL, '-NoProfile', '-NonInteractive', '-Command', self::BATTERY_STATUS],
            )),
            System::Other => Power::Mains,
        };
    }

    /** The power `pmset -g ps` and `pmset -g` say the machine draws. */
    public static function macos(string $source, string $settings): Power
    {
        return preg_match(self::ON_BATTERY, $source) === 1 || preg_match(self::LOW_POWER, $settings) === 1
            ? Power::Saving
            : Power::Mains;
    }

    /** The power PowerShell's battery status says the machine draws. */
    public static function windows(string $status): Power
    {
        return trim($status) === self::WINDOWS_DISCHARGING ? Power::Saving : Power::Mains;
    }

    /** Saving where the platform profile is low power or a battery discharges. */
    private function linux(): Power
    {
        if ($this->read(sprintf(self::PROFILE, $this->sys)) === self::LOW_POWER_PROFILE) {
            return Power::Saving;
        }

        $supplies = glob(sprintf(self::SUPPLIES, $this->sys));

        foreach (is_array($supplies) ? $supplies : [] as $supply) {
            $discharges = $this->read(sprintf(self::SUPPLY_FILE, $supply, 'status')) === self::DISCHARGING;

            if ($discharges && $this->read(sprintf(self::SUPPLY_FILE, $supply, 'type')) === self::BATTERY) {
                return Power::Saving;
            }
        }

        return Power::Mains;
    }

    /** What a file holds, trimmed; nothing where it cannot be read. */
    private function read(string $file): string
    {
        $read = is_readable($file) ? file_get_contents($file) : false;

        return is_string($read) ? trim($read) : '';
    }

    /**
     * What a program printed, or nothing where it is not there or failed.
     *
     * @param non-empty-list<string> $command
     */
    private function printed(array $command): string
    {
        if (! is_string(new ExecutableFinder()->find($command[0]))) {
            return '';
        }

        $process = new Process($command, timeout: self::READ_WITHIN);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }
}
