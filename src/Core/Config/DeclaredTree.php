<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

use function sprintf;

/**
 * A `trees` entry (ADR-0003): a path, the floor it declares, the reason a
 * floor of 0 needs, and the files in it that belong to no tree (ADR-0016).
 */
final readonly class DeclaredTree
{
    /** @param Listed<string> $exclude */
    private function __construct(
        private Path $path,
        private Floor|Exempt|Undeclared $declared,
        private Listed $exclude,
    ) {
    }

    /**
     * @param Listed<string> $exclude
     */
    public static function of(Path $path, Floor|Exempt|Undeclared $declared, Listed $exclude): self
    {
        return new self($path, $declared, $exclude);
    }

    public function path(): Path
    {
        return $this->path;
    }

    public function declared(): Floor|Exempt|Undeclared
    {
        return $this->declared;
    }

    /** @return Listed<string> `trees[].exclude`: globs from the repository root of files that belong to no tree */
    public function exclude(): Listed
    {
        return $this->exclude;
    }

    /** This entry as a config at this origin writes it. */
    public function written(Origin $origin): Json
    {
        $written = Json::object(Member::of('path', $origin->written($this->path)));
        $written = match (true) {
            $this->declared instanceof Floor => $written->with(Member::of('floor', $this->declared->written())),
            $this->declared instanceof Exempt => $written->with(
                Member::of('floor', 0),
            )->with(Member::of('reason', $this->declared->reason())),
            default => $written,
        };

        $excluded = [...$this->exclude];

        return $excluded === [] ? $written : $written->with(Member::of('exclude', Json::items(...$excluded)));
    }

    /** This entry as the builder's `Tree::at()` writes it. */
    public function php(Origin $origin): string
    {
        $arguments = PhpCalls::literal($origin->written($this->path));
        $arguments = match (true) {
            $this->declared instanceof Floor => sprintf(
                '%s, floor: %s',
                $arguments,
                PhpCalls::literal($this->declared->written()),
            ),
            $this->declared instanceof Exempt => sprintf(
                '%s, floor: 0, because: %s',
                $arguments,
                PhpCalls::literal($this->declared->reason()),
            ),
            default => $arguments,
        };
        $excluded = [...$this->exclude];

        return $excluded === []
            ? sprintf('Tree::at(%s)', $arguments)
            : sprintf(
                'Tree::at(%s)->excluding(%s)',
                $arguments,
                implode(
                    ', ',
                    array_map(
                        static fn(string $glob): string => sprintf('Glob::of(%s)', PhpCalls::literal($glob)),
                        $excluded,
                    ),
                ),
            );
    }
}
