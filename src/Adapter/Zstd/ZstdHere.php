<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Zstd;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\NoZstandard;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Format\Zstandard;

/**
 * The Zstandard this machine offers, found as each stream needs it: the
 * extension where it is loaded, since it starts no program, and the program
 * where it is not; where neither is here, nothing is packed or unpacked, and
 * the answer says what would.
 *
 * @internal
 */
final readonly class ZstdHere implements Zstandard
{
    private function __construct(private Zstandard $extension, private Zstandard $program)
    {
    }

    /** The extension, where it is loaded, and the program, as the path finds it. */
    public static function found(): self
    {
        return self::of(ZstdExtension::loaded(), ZstdProgram::named(ZstdProgram::PROGRAM));
    }

    public static function of(Zstandard $extension, Zstandard $program): self
    {
        return new self($extension, $program);
    }

    public function pack(string $text, int $level): string|CannotJudge
    {
        return $this->chosen()->pack($text, $level);
    }

    public function unpackAtMost(string $bytes, string $named, int $most): string|CannotJudge|TooLarge
    {
        return $this->chosen()->unpackAtMost($bytes, $named, $most);
    }

    public function isHere(): bool
    {
        return $this->chosen()->isHere();
    }

    /** The extension where it is loaded, the program where it is on the path, and nothing where neither is. */
    private function chosen(): Zstandard
    {
        return match (true) {
            $this->extension->isHere() => $this->extension,
            $this->program->isHere() => $this->program,
            default => NoZstandard::here(),
        };
    }
}
