<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

/**
 * The files an analyser's findings sit in, as the project spells them
 * (ADR-0020, decision 10): each path the analyser names, made relative to
 * the project's root and normalised, and a mutant's own file named as the
 * original it stands in for. A file outside the root is spelt as the
 * analyser names it, normalised.
 */
final readonly class FindingFiles
{
    /** @param array<string, Path> $originals the original each mutant stands in for, by the mutant's path */
    private function __construct(private Root $root, private array $originals)
    {
    }

    /** The files of a run over the originals, under the project's root. */
    public static function under(Root $root): self
    {
        return new self($root, []);
    }

    /** These, where a finding in the check's mutant sits in the original the mutant stands in for. */
    public function substituting(MutantCheck $check): self
    {
        $mutant = $this->spelt($check->mutant()->value())->value();

        return new self($this->root, [...$this->originals, $mutant => $this->spelt($check->original()->value())]);
    }

    /** The file a finding sits in, from the path the analyser names. */
    public function of(string $named): Path
    {
        $file = $this->spelt($named);

        return array_key_exists($file->value(), $this->originals) ? $this->originals[$file->value()] : $file;
    }

    /** A path, absolute or relative to the root, as the project spells it. */
    private function spelt(string $path): Path
    {
        return $this->root->relative(Path::of($path)->collapsed()->value());
    }
}
