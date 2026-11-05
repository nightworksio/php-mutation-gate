<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_bool;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * How much of the heatmap the reports show (ADR-0100): `heatmap`, which
 * the `Reports` part holds. The dashboard's summary lists the lowest-scoring
 * files, and every report that lists files lists their functions unless told
 * not to; how many files the comment lists is `comment.heatmap.most`
 * (ADR-0102).
 */
final readonly class HeatmapRows
{
    private const int FILES = 10;

    private function __construct(private int|Absent $files, private bool|Absent $methods)
    {
    }

    public static function of(int|Absent $files = new Absent(), bool|Absent $methods = new Absent()): self
    {
        return new self($files, $methods);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->files, $later->files),
            Absent::laid($this->methods, $later->methods),
        );
    }

    /** `heatmap.files`: how many of the lowest-scoring files the dashboard's summary lists. */
    public function files(): int
    {
        return is_int($this->files) ? $this->files : self::FILES;
    }

    /** `heatmap.methods`: whether the heatmap scores each function of a file too. */
    public function methods(): bool
    {
        return ! is_bool($this->methods) || $this->methods;
    }

    /** The `heatmap` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        $heatmap = Json::object(
            Member::of('files', $this->files),
            Member::of('methods', $this->methods),
        );

        return Member::unlessEmpty('heatmap', $heatmap);
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        $written = $this->files instanceof Absent
            ? []
            : [sprintf('Heatmap::files(%s)', PhpCalls::literal($this->files))];

        $methods = match ($this->methods) {
            true => ['Heatmap::withMethods()'],
            false => ['Heatmap::withoutMethods()'],
            default => [],
        };

        return PhpCalls::inWith(...$written, ...$methods);
    }
}
