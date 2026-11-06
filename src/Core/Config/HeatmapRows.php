<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_bool;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * How much of the heatmap each report shows (ADR-0100): `heatmap`, which
 * the `Reports` part holds. The dashboard's summary lists the lowest-scoring
 * files, the pull request comment the files the change reaches that fell
 * most or sit below their floor, and every report that lists files lists
 * their functions unless told not to.
 */
final readonly class HeatmapRows
{
    private const int FILES = 10;

    private const int COMMENT = 5;

    private function __construct(private int|Absent $files, private int|Absent $comment, private bool|Absent $methods)
    {
    }

    public static function of(
        int|Absent $files = new Absent(),
        int|Absent $comment = new Absent(),
        bool|Absent $methods = new Absent(),
    ): self {
        return new self($files, $comment, $methods);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->files, $later->files),
            Absent::laid($this->comment, $later->comment),
            Absent::laid($this->methods, $later->methods),
        );
    }

    /** `heatmap.files`: how many of the lowest-scoring files the dashboard's summary lists. */
    public function files(): int
    {
        return is_int($this->files) ? $this->files : self::FILES;
    }

    /** `heatmap.comment`: how many files that fell the pull request comment lists; none turns its heatmap off. */
    public function comment(): int
    {
        return is_int($this->comment) ? $this->comment : self::COMMENT;
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
            Member::of('comment', $this->comment),
            Member::of('methods', $this->methods),
        );

        return Member::unlessEmpty('heatmap', $heatmap);
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        $calls = ['files' => $this->files, 'comment' => $this->comment];
        $written = [];

        foreach ($calls as $method => $value) {
            $written = $value instanceof Absent
                ? $written
                : [...$written, sprintf('Heatmap::%s(%s)', $method, PhpCalls::literal($value))];
        }

        $methods = match ($this->methods) {
            true => ['Heatmap::withMethods()'],
            false => ['Heatmap::withoutMethods()'],
            default => [],
        };

        return PhpCalls::inWith(...$written, ...$methods);
    }
}
