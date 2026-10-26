<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function clearstatcache;
use function filesize;
use function is_file;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where a warm worker's output files stood at a moment: the byte its
 * standard output had reached, and its standard error's. A worker runs one
 * child at a time and prints nothing itself while one runs, so what a child
 * printed is what the files grew by from its fork to its end.
 */
final readonly class Offsets
{
    private function __construct(private int $out, private int $err)
    {
    }

    public static function of(int $out, int $err): self
    {
        return new self($out, $err);
    }

    /** Where output files stand before anything is printed to them. */
    public static function none(): self
    {
        return new self(0, 0);
    }

    /** Where the place's output files stand now. */
    public static function now(Seat $seat): self
    {
        clearstatcache();

        return new self(self::sizeOf($seat->out()), self::sizeOf($seat->err()));
    }

    public static function read(Node $at): self
    {
        return new self($at->field('out')->integer(), $at->field('err')->integer());
    }

    /** @return list<Member> */
    public function members(): array
    {
        return [Member::of('out', $this->out), Member::of('err', $this->err)];
    }

    /** The offsets written to this file, under its name only once they are all there. */
    public function writtenTo(string $file): void
    {
        WholeFile::written($file, Json::object(...$this->members())->line());
    }

    /** The offsets written to this file; none where none are written whole. */
    public static function readFrom(string $file): self|NotGiven
    {
        $at = WholeFile::read($file);

        try {
            return $at instanceof Node ? self::read($at) : $at;
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    public function out(): int
    {
        return $this->out;
    }

    public function err(): int
    {
        return $this->err;
    }

    /** A file's size in bytes; none where there is no file. */
    private static function sizeOf(string $file): int
    {
        $size = is_file($file) ? filesize($file) : false;

        return $size === false ? 0 : $size;
    }
}
