<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordField;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a warm worker's child ran a mutant's run, as the worker writes it once
 * the child is gone (ADR-0023): the code it exited with, or the signal that
 * ended it, with where the worker's output files stood then; or that the
 * worker forked it no child, and why, so its run starts fresh.
 */
final readonly class Finish
{
    private const string CODE = RecordField::Code->value;

    private const string SIGNAL = 'signal';

    private const string REFUSED = 'unforked';

    private function __construct(
        private int|NotGiven $code,
        private int|NotGiven $signal,
        private string|NotGiven $refused,
        private Offsets $to,
    ) {
    }

    public static function exited(int $code, Offsets $to): self
    {
        return new self($code, NotGiven::value(), NotGiven::value(), $to);
    }

    public static function signalled(int $signal, Offsets $to): self
    {
        return new self(NotGiven::value(), $signal, NotGiven::value(), $to);
    }

    /** No child forked for the run, for this reason. */
    public static function refused(string $reason): self
    {
        return new self(NotGiven::value(), NotGiven::value(), $reason, Offsets::of(0, 0));
    }

    /** The finish a worker wrote to this file; none where it has written none whole. */
    public static function readFrom(string $file): self|NotGiven
    {
        $at = WholeFile::read($file);

        try {
            return $at instanceof Node ? self::read($at) : $at;
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    /** Written whole, under its name only once it is all there. */
    public function writtenTo(string $file): void
    {
        WholeFile::written($file, $this->written()->line());
    }

    /** Whether the worker forked the run no child, so it starts fresh. */
    public function isRefused(): bool
    {
        return is_string($this->refused);
    }

    /** The code the child exited with; none where a signal ended it or none ran. */
    public function code(): int|NotGiven
    {
        return $this->code;
    }

    /** The signal that ended the child; none where it exited or none ran. */
    public function signal(): int|NotGiven
    {
        return $this->signal;
    }

    /** Where the worker's output files stood once the child was gone. */
    public function to(): Offsets
    {
        return $this->to;
    }

    private static function read(Node $at): self
    {
        $code = $at->field(self::CODE);
        $signal = $at->field(self::SIGNAL);
        $refused = $at->field(self::REFUSED);

        return $refused->isPresent() ? self::refused($refused->text()) : new self(
            $code->isPresent() ? $code->integer() : NotGiven::value(),
            $signal->isPresent() ? $signal->integer() : NotGiven::value(),
            NotGiven::value(),
            Offsets::read($at),
        );
    }

    private function written(): Json
    {
        return match (true) {
            is_string($this->refused) => Json::object(Member::of(self::REFUSED, $this->refused)),
            is_int($this->signal) => Json::object(Member::of(self::SIGNAL, $this->signal), ...$this->to->members()),
            default => Json::object(
                Member::of(self::CODE, is_int($this->code) ? $this->code : 0),
                ...$this->to->members(),
            ),
        };
    }
}
