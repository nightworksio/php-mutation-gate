<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function clearstatcache;
use function fclose;
use function file_exists;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function is_int;
use function is_resource;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * What one child printed: the bytes of its worker's standard output and error
 * from where they stood when the child was forked to where they stood once it
 * ended. A worker runs one child at a time, and prints nothing itself while
 * one runs, so those bytes are the child's alone.
 */
final readonly class Printed
{
    private function __construct(
        private int $place,
        private int $outFrom,
        private int $outTo,
        private int $errFrom,
        private int $errTo,
    ) {
    }

    /** Where the worker in a place has printed to, so far: what a child forked now prints starts there. */
    public static function from(Workplace $workplace, int $place): self
    {
        $out = self::sizeOf($workplace->out($place));
        $err = self::sizeOf($workplace->err($place));

        return new self($place, $out, $out, $err, $err);
    }

    /** What was printed from here to where the worker's files stand now. */
    public function untilNow(Workplace $workplace): self
    {
        return new self(
            $this->place,
            $this->outFrom,
            self::sizeOf($workplace->out($this->place)),
            $this->errFrom,
            self::sizeOf($workplace->err($this->place)),
        );
    }

    /** Everything the worker in a place and its children printed, as a failed worker leaves it. */
    public static function whole(Workplace $workplace, int $place): string
    {
        $now = self::from($workplace, $place);

        return new self($place, 0, $now->outTo, 0, $now->errTo)->text($workplace);
    }

    /** What an end record says was printed. */
    public static function read(Node $record): self
    {
        $out = $record->field('out')->integers();
        $err = $record->field('err')->integers();

        return new self($record->field('place')->integer(), $out[0], $out[1], $err[0], $err[1]);
    }

    /** @return list<Member> */
    public function members(): array
    {
        return [
            Member::of('place', $this->place),
            Member::of('out', Json::items($this->outFrom, $this->outTo)),
            Member::of('err', Json::items($this->errFrom, $this->errTo)),
        ];
    }

    /** The bytes printed, on standard output and then on standard error. */
    public function text(Workplace $workplace): string
    {
        return sprintf(
            '%s%s',
            $this->bytesOf($workplace->out($this->place), $this->outFrom, $this->outTo),
            $this->bytesOf($workplace->err($this->place), $this->errFrom, $this->errTo),
        );
    }

    private static function sizeOf(string $file): int
    {
        clearstatcache(clear_realpath_cache: true, filename: $file);
        $size = file_exists($file) ? filesize($file) : 0;

        return is_int($size) ? $size : 0;
    }

    private function bytesOf(string $file, int $from, int $to): string
    {
        $handle = $to > $from ? fopen($file, 'r') : false;

        if (! is_resource($handle)) {
            return '';
        }

        fseek($handle, $from);
        $bytes = fread($handle, max(1, $to - $from));
        fclose($handle);

        return is_string($bytes) ? $bytes : '';
    }
}
