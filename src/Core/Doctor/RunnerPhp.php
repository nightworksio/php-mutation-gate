<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_key_exists;
use function is_string;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The PHP a runner runs its tests on, as it describes itself with the
 * runner's own options: the extensions it loads, the ones installed beside
 * them that it does not, its settings, the php.ini it loads, the variables it
 * sees, and whether the runner turns opcache off on each mutant's command
 * line.
 *
 * @internal
 */
final readonly class RunnerPhp
{
    /**
     * @param array<string, true>   $loaded    each extension it loads, lower-cased
     * @param array<string, true>   $offered   each extension installed in its extension directory, lower-cased
     * @param array<string, string> $settings  each setting's value, by name
     * @param array<string, string> $variables each variable it sees, by name
     */
    private function __construct(
        private string $binary,
        private array $loaded,
        private array $offered,
        private array $settings,
        private array $variables,
        private string|NotGiven $iniFile,
        private bool $opcacheOff,
    ) {
    }

    public static function at(string $binary): self
    {
        return new self($binary, [], [], [], [], NotGiven::value(), opcacheOff: false);
    }

    /** This PHP, loading these extensions as well, by name in any case. */
    public function loading(string ...$extensions): self
    {
        return new self(
            $this->binary,
            $this->named($this->loaded, $extensions),
            $this->offered,
            $this->settings,
            $this->variables,
            $this->iniFile,
            $this->opcacheOff,
        );
    }

    /** This PHP, with these extensions installed where it could load them. */
    public function offering(string ...$extensions): self
    {
        return new self(
            $this->binary,
            $this->loaded,
            $this->named($this->offered, $extensions),
            $this->settings,
            $this->variables,
            $this->iniFile,
            $this->opcacheOff,
        );
    }

    /** This PHP, with a setting's value. */
    public function setting(string $name, string $value): self
    {
        return new self(
            $this->binary,
            $this->loaded,
            $this->offered,
            [...$this->settings, $name => $value],
            $this->variables,
            $this->iniFile,
            $this->opcacheOff,
        );
    }

    /** This PHP, seeing a variable of the environment it runs in. */
    public function seeing(string $name, string $value): self
    {
        return new self(
            $this->binary,
            $this->loaded,
            $this->offered,
            $this->settings,
            [...$this->variables, $name => $value],
            $this->iniFile,
            $this->opcacheOff,
        );
    }

    /** This PHP, loading a php.ini. */
    public function loadingIni(string $file): self
    {
        return new self(
            $this->binary,
            $this->loaded,
            $this->offered,
            $this->settings,
            $this->variables,
            $file,
            $this->opcacheOff,
        );
    }

    /**
     * This PHP, as a runner starts each mutant's run on it with opcache off
     * on its command line, so opcache serves nothing, whatever its settings
     * (ADR-0023).
     */
    public function turningOpcacheOff(): self
    {
        return new self(
            $this->binary,
            $this->loaded,
            $this->offered,
            $this->settings,
            $this->variables,
            $this->iniFile,
            opcacheOff: true,
        );
    }

    /** Whether the runner turns opcache off on each mutant's command line. */
    public function turnsOpcacheOff(): bool
    {
        return $this->opcacheOff;
    }

    /** The PHP binary the runner starts. */
    public function binary(): string
    {
        return $this->binary;
    }

    public function loads(string $extension): bool
    {
        return array_key_exists(mb_strtolower($extension), $this->loaded);
    }

    /** Whether an extension is there to load, loaded or not. */
    public function offers(string $extension): bool
    {
        return $this->loads($extension) || array_key_exists(mb_strtolower($extension), $this->offered);
    }

    public function valueOf(string $setting): string|NotGiven
    {
        return array_key_exists($setting, $this->settings) ? $this->settings[$setting] : NotGiven::value();
    }

    /** The php.ini this PHP loads, or a description of it where it loads none. */
    public function iniFile(): string
    {
        return is_string($this->iniFile) ? $this->iniFile : 'the php.ini this PHP loads';
    }

    public function variable(string $name): string|NotGiven
    {
        return array_key_exists($name, $this->variables) ? $this->variables[$name] : NotGiven::value();
    }

    /**
     * @param  array<string, true> $held
     * @param  array<string>       $more
     * @return array<string, true>
     */
    private function named(array $held, array $more): array
    {
        foreach ($more as $extension) {
            $held[mb_strtolower($extension)] = true;
        }

        return $held;
    }
}
