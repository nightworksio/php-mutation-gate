<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;
use function str_starts_with;

/**
 * A path a config wrote, with the directory it was written from (ADR-0002):
 * `src` in `ci/mutation-gate.json` is `ci/src`, and `../src` there is `src`.
 * An absolute path is itself wherever it is written.
 */
final readonly class ConfigPath
{
    /** @param string $directory where it is written from, from the project; '' for the project itself */
    private function __construct(private string $written, private string $directory)
    {
    }

    /** @param string $directory where it is written from, from the project; '' for the project itself */
    public static function of(string $written, string $directory): self
    {
        return new self($written, $directory);
    }

    /** The path from the project, each `..` taking back the directory before it. */
    public function path(): Path
    {
        $joined = $this->directory === '' || str_starts_with($this->written, '/')
            ? $this->written
            : sprintf('%s/%s', $this->directory, $this->written);

        return Path::of($joined)->collapsed();
    }
}
