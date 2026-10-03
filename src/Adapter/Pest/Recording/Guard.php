<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function file_put_contents;
use function getenv;
use function is_string;
use function json_encode;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Runner\Opcache;

/**
 * What a run the adapter starts on a mutant of a line that is not executable
 * says about itself, written to the file `MUTATION_GATE_GUARD` names once the
 * run ends: whether the original file was loaded before Pest's override
 * started, whether it was loaded at all, and whether opcache could have
 * served a cached original. Each makes the run's result one the gate cannot
 * trust.
 */
final readonly class Guard
{
    private function __construct(private string $file, private string $original, private bool $before)
    {
    }

    /**
     * Guarding where the adapter named a file and the override an original,
     * by the files loaded before the override started.
     *
     * @param list<string> $loaded
     */
    public static function fromEnvironment(array $loaded): self|Off
    {
        return self::watching(getenv(GateVariable::Guard->value), getenv(Recorder::MUTANT), Loaded::of($loaded));
    }

    public static function watching(string|false $file, string|false $original, Loaded $loaded): self|Off
    {
        if (! is_string($file) || $file === '' || ! is_string($original)) {
            return Off::Guarding;
        }

        return new self($file, $original, $loaded->has($original));
    }

    /**
     * Writes what it saw, given every file loaded by the end of the run.
     *
     * @param list<string> $loaded
     */
    public function write(array $loaded, Opcache $opcache): void
    {
        $seen = [
            'before' => $this->before,
            'loaded' => Loaded::of($loaded)->has($this->original),
            'opcache' => $opcache->couldServeTheOriginal(),
        ];

        file_put_contents($this->file, json_encode($seen, JSON_UNESCAPED_SLASHES));
    }
}
