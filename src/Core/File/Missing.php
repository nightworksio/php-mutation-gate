<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/** A file that is not there. */
final readonly class Missing
{
    /** What a digest of several files holds in place of one that is not there. */
    public const string DIGESTED = 'missing';

    private function __construct(private Path $path)
    {
    }

    public static function at(Path $path): self
    {
        return new self($path);
    }

    public function path(): Path
    {
        return $this->path;
    }
}
