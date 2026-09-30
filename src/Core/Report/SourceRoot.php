<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function explode;
use function implode;
use function rawurlencode;
use function sprintf;
use function str_replace;
use function strtr;
use function trim;

/**
 * Where the repository sits on this machine, as the `file://` URI an
 * editor's SARIF viewer resolves the report's relative paths against
 * (ADR-0015, decision 9).
 */
final readonly class SourceRoot
{
    private function __construct(private string $uri)
    {
    }

    /** The root at this absolute directory, spelt with `/` or `\`. */
    public static function at(string $directory): self
    {
        $path = trim(str_replace('\\', '/', $directory), '/');
        $segments = array_map(
            static fn(string $segment): string => strtr(rawurlencode($segment), ['%3A' => ':']),
            explode('/', $path),
        );

        return new self($path === '' ? 'file:///' : sprintf('file:///%s/', implode('/', $segments)));
    }

    /** The URI, ending in `/` so every relative path resolves inside it. */
    public function uri(): string
    {
        return $this->uri;
    }
}
