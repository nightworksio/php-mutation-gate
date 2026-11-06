<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use NightWorksIO\MutationGate\Core\CannotJudge;

/**
 * Zstandard, as this machine offers it: the zstd extension, the zstd
 * program, or neither. Unpacking reads a whole stream, inflating it no
 * further than a limit.
 *
 * @internal
 */
interface Zstandard
{
    /** Text packed at this level; or why it cannot be, as where neither the extension nor the program is here. */
    public function pack(string $text, int $level): string|CannotJudge;

    /**
     * The text a stream holds, where it inflates to no more than so many bytes; or why it cannot be read, as
     * where neither the extension nor the program is here.
     */
    public function unpackAtMost(string $bytes, string $named, int $most): string|CannotJudge|TooLarge;

    /** Whether it packs and unpacks at all. */
    public function isHere(): bool;
}
