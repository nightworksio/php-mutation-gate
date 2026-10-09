<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Format\Series;
use NightWorksIO\MutationGate\Core\Score\Exempt;

use function sprintf;

/**
 * The trees zero-config found, which `init` lists rather than writes
 * (ADR-0017, decision 2): a run finds them again, so a tree added later is
 * judged with no edit to the config. PHP, YAML and NEON configs list them in
 * a comment; for JSON, which holds none, `init` says them.
 */
final readonly class FoundTrees
{
    private const string HEADING = 'Zero-config finds these trees, so this file names none:';

    private const string SAID = 'Zero-config finds the trees %s, so the config names none.';

    private const string ITEM = '  %s';

    private const string EXEMPT = '%s (exempt: %s)';

    private function __construct(private Trees $trees)
    {
    }

    public static function of(Trees $trees): self
    {
        return new self($trees);
    }

    /**
     * The lines that list the trees in this config file: a heading, then one
     * tree to a line, its path as the file would write it.
     *
     * @return list<string>
     */
    public function lines(ConfigFile $file): array
    {
        $lines = [self::HEADING];

        foreach ($this->trees as $tree) {
            $lines[] = sprintf(self::ITEM, $this->named($tree, $file->written($tree->path())));
        }

        return $lines;
    }

    /** The trees said in one sentence. */
    public function said(): string
    {
        $named = [];

        foreach ($this->trees as $tree) {
            $named[] = $this->named($tree, $tree->path()->value());
        }

        return sprintf(self::SAID, Series::and(...$named));
    }

    private function named(Tree $tree, string $path): string
    {
        $declared = $tree->declared();

        return $declared instanceof Exempt ? sprintf(self::EXEMPT, $path, $declared->reason()) : $path;
    }
}
