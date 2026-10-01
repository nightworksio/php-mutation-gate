<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;

/** A named function or method the project declares: its file, its name as written, and the lines of its body. */
final readonly class Declared
{
    private function __construct(private Path $file, private string $name, private Line $first, private Line $last)
    {
    }

    /** A function or method of a name, whose body runs from one line to another. */
    public static function in(Path $file, string $name, Line $first, Line $last): self
    {
        return new self($file, $name, $first, $last);
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Whether a line of its file is in its body, from the line of its opening brace to that of its closing one. */
    public function holds(Line $line): bool
    {
        return $this->first->number() <= $line->number() && $line->number() <= $this->last->number();
    }
}
