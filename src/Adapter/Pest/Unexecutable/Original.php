<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\Source;

/** A mutated file's original as its mutants are judged against it: printed as Pest prints it, and scanned. */
final readonly class Original
{
    private function __construct(private Contents $printed, private Source $source)
    {
    }

    public static function of(Contents $printed, Source $source): self
    {
        return new self($printed, $source);
    }

    public function printed(): Contents
    {
        return $this->printed;
    }

    public function source(): Source
    {
        return $this->source;
    }
}
