<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function trim;

/**
 * `badge.logo`: the SVG a badge shows beside its label, as shields.io's
 * endpoint reads it from `logoSvg`, or none (ADR-0009).
 *
 * @internal
 */
final readonly class BadgeLogo
{
    /** The file of the gate's own logo, the helix, under the package's `resources/badge/`. */
    public const string HELIX = 'helix.svg';

    private function __construct(private string $svg)
    {
    }

    public static function drawn(string $svg): self
    {
        return new self(trim($svg));
    }

    public static function none(): self
    {
        return new self('');
    }

    /** @return array<string, string> the badge's `logoSvg`, or nothing where it shows no logo */
    public function fields(): array
    {
        return $this->svg === '' ? [] : ['logoSvg' => $this->svg];
    }
}
