<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

use function sprintf;

/**
 * What decides a score and the floor it is held to (ADR-0003): `trees`,
 * `newCode`, `security`, `uncovered`, `baseline`, and the floor of each owner
 * CODEOWNERS names that has one, `owners.floors` (ADR-0022).
 */
final readonly class Floors implements Part
{
    private const string BASELINE = 'mutation-gate.baseline.json';

    /** @param Listed<DeclaredTree>|Absent $trees */
    private function __construct(
        private Listed|Absent $trees,
        private Floor|Absent $newCode,
        private Floor|Absent $security,
        private Uncovered|Absent $uncovered,
        private Path|Absent $baseline,
        private Improvement|Absent $improvement,
        private Table|Absent $owners,
    ) {
    }

    /** @param Listed<DeclaredTree>|Absent $trees */
    public static function of(
        Listed|Absent $trees = new Absent(),
        Floor|Absent $newCode = new Absent(),
        Floor|Absent $security = new Absent(),
        Uncovered|Absent $uncovered = new Absent(),
        Path|Absent $baseline = new Absent(),
        Improvement|Absent $improvement = new Absent(),
        Table|Absent $owners = new Absent(),
    ): self {
        return new self($trees, $newCode, $security, $uncovered, $baseline, $improvement, $owners);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of(
            newCode: $none->newCode(),
            uncovered: $none->uncovered(),
            baseline: $none->baseline(),
            improvement: $none->improvement(),
            owners: $none->owners(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->trees, $later->trees),
                Absent::laid($this->newCode, $later->newCode),
                Absent::laid($this->security, $later->security),
                Absent::laid($this->uncovered, $later->uncovered),
                Absent::laid($this->baseline, $later->baseline),
                Absent::laid($this->improvement, $later->improvement),
                Absent::laid($this->owners, $later->owners),
            )
            : $this;
    }

    /** @return Listed<DeclaredTree>|Absent the trees the config declares, or none, when the tree source finds them */
    public function trees(): Listed|Absent
    {
        return $this->trees;
    }

    /** `newCode.floor` */
    public function newCode(): Floor
    {
        return $this->newCode instanceof Floor ? $this->newCode : Floor::whole();
    }

    /** `security.floor`, which declares none by default (ADR-0021) */
    public function security(): Floor|Undeclared
    {
        return $this->security instanceof Floor ? $this->security : Undeclared::floor();
    }

    public function uncovered(): Uncovered
    {
        return $this->uncovered instanceof Uncovered ? $this->uncovered : Uncovered::Count;
    }

    /** `baseline.path` */
    public function baseline(): Path
    {
        return $this->baseline instanceof Path ? $this->baseline : Path::of(self::BASELINE);
    }

    /** `baseline.improvement` */
    public function improvement(): Improvement
    {
        return $this->improvement instanceof Improvement ? $this->improvement : Improvement::Require;
    }

    /** `owners.floors`: the floor of each owner that has one, by the owner as CODEOWNERS spells it; none by default */
    public function owners(): Table
    {
        return $this->owners instanceof Table ? $this->owners : Table::none();
    }

    public function written(PathOrigin $origin): Json
    {
        $trees = $this->trees instanceof Listed ? Json::items(...array_map(
            static fn(DeclaredTree $tree): Json => $tree->written($origin),
            [...$this->trees],
        )) : $this->trees;

        return Json::object(
            Member::of('trees', $trees),
            Member::unlessEmpty(
                'newCode',
                Json::object(
                    Member::of('floor', $this->newCode instanceof Floor ? $this->newCode->written() : $this->newCode),
                ),
            ),
            Member::unlessEmpty(
                'security',
                Json::object(
                    Member::of(
                        'floor',
                        $this->security instanceof Floor ? $this->security->written() : $this->security,
                    ),
                ),
            ),
            Member::of(
                'uncovered',
                $this->uncovered instanceof Uncovered ? $this->uncovered->value : $this->uncovered,
            ),
            Member::unlessEmpty(
                'baseline',
                Json::object(
                    Member::of(
                        'path',
                        $this->baseline instanceof Path ? $origin->written($this->baseline) : $this->baseline,
                    ),
                    Member::of(
                        'improvement',
                        $this->improvement instanceof Improvement ? $this->improvement->value : $this->improvement,
                    ),
                ),
            ),
            Member::unlessEmpty(
                'owners',
                Json::object(
                    Member::of('floors', $this->owners instanceof Table ? $this->owners->written() : $this->owners),
                ),
            ),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = $this->trees instanceof Listed
            ? PhpCalls::onGate(
                GateMethod::Trees,
                ...array_map(
                    static fn(DeclaredTree $tree): string => $tree->php($origin),
                    [...$this->trees],
                ),
            )
            : PhpCalls::none();
        $calls = $this->newCode instanceof Floor
            ? $calls->and(
                PhpCalls::onGate(
                    GateMethod::NewCode,
                    sprintf('Floor::of(%s)', PhpCalls::literal($this->newCode->written())),
                ),
            )
            : $calls;
        $calls = $this->security instanceof Floor
            ? $calls->and(
                PhpCalls::onGate(
                    GateMethod::Security,
                    sprintf('Floor::of(%s)', PhpCalls::literal($this->security->written())),
                ),
            )
            : $calls;

        return $calls->and(PhpCalls::inWith(...$this->settings($origin)));
    }

    /** @return list<string> the settings `with()` takes for what this part sets */
    private function settings(PathOrigin $origin): array
    {
        $uncovered = $this->uncovered instanceof Uncovered ? [match ($this->uncovered) {
            Uncovered::Count => 'Uncovered::counted()',
            Uncovered::Exclude => 'Uncovered::excluded()',
        }] : [];
        $baseline = $this->baseline instanceof Path
            ? [sprintf('Baseline::at(%s)', PhpCalls::literal($origin->written($this->baseline)))]
            : [];
        $improvement = $this->improvement instanceof Improvement ? [match ($this->improvement) {
            Improvement::Require => 'Baseline::requiringImprovement()',
            Improvement::Report => 'Baseline::reportingImprovement()',
        }] : [];

        return [...$uncovered, ...$baseline, ...$improvement, ...$this->ownerCalls()];
    }

    /** @return list<string> the `Owner::floor()` settings for each owner's floor */
    private function ownerCalls(): array
    {
        $calls = [];

        foreach ($this->owners instanceof Table ? $this->owners : [] as $owner => $floor) {
            $calls[] = sprintf('Owner::floor(%s, %s)', PhpCalls::literal($owner), PhpCalls::literal($floor));
        }

        return $calls;
    }
}
