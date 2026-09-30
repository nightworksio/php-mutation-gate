<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Nameless;

/** The named function or method a mutant is in, by its file and its name, as a hint names it. */
final readonly class Enclosing
{
    /** @param non-empty-string $function */
    private function __construct(private Path $file, private string $function)
    {
    }

    /** @param non-empty-string $function */
    public static function named(Path $file, string $function): self
    {
        return new self($file, $function);
    }

    /**
     * The function a mutant is in, as `Functions::around()` names it, or
     * nameless code where it is in no named one.
     *
     * @param non-empty-string|Nameless $function
     */
    public static function of(Path $file, string|Nameless $function): self|Nameless
    {
        return $function instanceof Nameless ? $function : new self($file, $function);
    }

    public function file(): Path
    {
        return $this->file;
    }

    /** @return non-empty-string */
    public function function(): string
    {
        return $this->function;
    }
}
