<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_key_exists;
use function is_string;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The PHP a runner runs its tests on, as `php -m` and `php -i` describe it
 * with the runner's own options: the extensions it loads, the ones installed
 * beside them that it does not, its settings, and the variables it sees.
 */
final readonly class RunnerPhp
{
    /** How `php -i` names the php.ini a PHP loaded. */
    public const string LOADED_INI = 'Loaded Configuration File';

    /** What `php -i` says where a PHP loads no php.ini. */
    private const string NO_INI = '(none)';

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
    ) {
    }

    public static function at(string $binary): self
    {
        return new self($binary, [], [], [], []);
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
        );
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

    /** The php.ini this PHP loads, as `php -i` names it, or a description of it where it loads none. */
    public function iniFile(): string
    {
        $file = $this->valueOf(self::LOADED_INI);

        return is_string($file) && $file !== self::NO_INI ? $file : 'the php.ini this PHP loads';
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
