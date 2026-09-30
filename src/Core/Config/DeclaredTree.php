<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Items;
use NightWorksIO\MutationGate\Core\Config\Definition\Location;
use NightWorksIO\MutationGate\Core\Config\Definition\Percent;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
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

    /**
     * A `trees` entry as a config writes it: a floor of 0 has to carry its reason.
     *
     * @return Section<self>
     */
    public static function shape(Origin $origin): Section
    {
        $results = Effect::AffectsResults;
        $judges = Effect::JudgesOrReportsOnly;
        $path = Field::required('path', Location::path($origin), $results);
        $floor = Field::optional('floor', Percent::floor(), $judges);
        $reason = Field::optional('reason', Text::of('a reason'), $judges);
        $exclude = Field::optional('exclude', Items::of(Text::of('a glob')), $results);

        return Section::of(
            static function (Node $tree) use ($path, $floor, $reason, $exclude): self|Invalid {
                $at = $path->read($tree);
                $declared = $floor->read($tree);
                $because = $reason->read($tree);
                $excluded = $exclude->read($tree);

                return Reading::built(
                    static fn(): self|Invalid => self::read(
                        $tree,
                        $at->must(),
                        $declared->value(),
                        $because->value(),
                        $excluded->value(),
                    ),
                    $at,
                    $declared,
                    $because,
                    $excluded,
                );
            },
            $path,
            $floor,
            $reason,
            $exclude,
        )->defaulting(Json::object()->with('exclude', Json::items([])));
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
        $written = Json::object()->with('path', $origin->written($this->path));
        $written = match (true) {
            $this->declared instanceof Floor => $written->with('floor', $this->declared->written()),
            $this->declared instanceof Exempt => $written->with('floor', 0)->with('reason', $this->declared->reason()),
            default => $written,
        };

        $excluded = [...$this->exclude];

        return $excluded === [] ? $written : $written->with('exclude', Json::items($excluded));
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
            : sprintf('Tree::at(%s, excluding: [%s])', $arguments, PhpCalls::literals($excluded));
    }

    /** @param Listed<string>|Absent $exclude */
    private static function read(
        Node $tree,
        Path $path,
        Floor|Absent $floor,
        string|Absent $reason,
        Listed|Absent $exclude,
    ): self|Invalid {
        $excluding = $exclude instanceof Absent ? Listed::of([]) : $exclude;

        return match (true) {
            $floor instanceof Absent => new self($path, Undeclared::floor(), $excluding),
            $floor->hundredths() > 0 => new self($path, $floor, $excluding),
            ! $reason instanceof Absent => new self($path, Exempt::because($reason), $excluding),
            default => Invalid::because(
                Problem::at($tree->field('reason')->at(), 'expected a reason when floor is 0, got nothing'),
            ),
        };
    }
}
