<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Format\Zstandard;

use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * A Zstandard that packs text behind zstd's first bytes and the level it
 * was asked for, and unpacks what it packed; or one that is not here.
 */
final readonly class ZstandardFake implements Zstandard
{
    /** How every zstd frame begins. */
    public const string MAGIC = "\x28\xb5\x2f\xfd";

    private function __construct(private bool $here, private string $name)
    {
    }

    public static function here(string $name = 'fake'): self
    {
        return new self(here: true, name: $name);
    }

    public static function absent(string $name = 'fake'): self
    {
        return new self(here: false, name: $name);
    }

    public function pack(string $text, int $level): string|CannotJudge
    {
        return $this->here
            ? sprintf('%s%s:%02d:%s', self::MAGIC, $this->name, $level, $text)
            : CannotJudge::because(sprintf('%s is not here', $this->name));
    }

    public function unpackAtMost(string $bytes, string $named, int $most): string|CannotJudge|TooLarge
    {
        $prefix = sprintf('%s%s:', self::MAGIC, $this->name);
        $text = substr($bytes, strlen($prefix) + 3);

        return match (true) {
            ! $this->here => CannotJudge::because(sprintf('%s is not here', $this->name)),
            ! str_starts_with($bytes, $prefix) => CannotJudge::because(sprintf('%s is not %s\'s', $named, $this->name)),
            strlen($text) > $most => TooLarge::because(sprintf('%s inflates past %d', $named, $most)),
            default => $text,
        };
    }

    public function isHere(): bool
    {
        return $this->here;
    }
}
