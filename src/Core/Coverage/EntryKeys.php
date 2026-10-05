<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_replace;
use function ksort;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function strval;

/**
 * The key of each test file's coverage entries, which a kept map records as
 * `keys` (ADR-0023, decision 1): one digest over what the entries could
 * depend on. An entry whose key is unchanged is reused, and one whose key
 * moved, or that has none, is measured again.
 */
final readonly class EntryKeys
{
    /** The field a map records the keys in. */
    public const string FIELD = 'keys';

    /** @param array<array-key, Digest> $keys each test file's key, by its path */
    private function __construct(private array $keys)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** The keys a decoded map records in its field, each that is no path and digest dropped. */
    public static function readIn(Node $map): self
    {
        try {
            $entries = $map->field(self::FIELD)->entries();
        } catch (NotInShape) {
            return self::none();
        }

        $keys = [];

        foreach ($entries as $path => $key) {
            $keys += self::keyIn(strval($path), $key);
        }

        return new self($keys);
    }

    /** These keys, with a test file's, in place of any it had. */
    public function with(Path $file, Digest $key): self
    {
        $keys = $this->keys;
        $keys[$file->value()] = $key;

        return new self($keys);
    }

    /** These keys, but those of these test files. */
    public function without(Paths $files): self
    {
        $keys = $this->keys;

        foreach ($files as $file) {
            unset($keys[$file->value()]);
        }

        return new self($keys);
    }

    /** These keys, with those of other keys in place of any they share. */
    public function and(self $other): self
    {
        return new self(array_replace($this->keys, $other->keys));
    }

    /** A test file's key; or none, where these keys hold none for it. */
    public function keyOf(Path $file): Digest|Missing
    {
        return array_key_exists($file->value(), $this->keys) ? $this->keys[$file->value()] : Missing::at($file);
    }

    /** Every test file these keys hold one for. */
    public function files(): Paths
    {
        return Paths::of(
            ...array_map(static fn(int|string $file): Path => Path::of(strval($file)), array_keys($this->keys)),
        );
    }

    /** @return array<string, string> each key's digest, by its test file, in byte order, as a map records them */
    public function written(): array
    {
        $written = [];

        foreach ($this->keys as $file => $key) {
            $written[strval($file)] = $key->value();
        }

        ksort($written);

        return $written;
    }

    /**
     * A test file's key, where its path is one and its value a digest; none otherwise.
     *
     * @return array<string, Digest>
     */
    private static function keyIn(string $path, Node $key): array
    {
        try {
            $digest = $key->text();
        } catch (NotInShape) {
            return [];
        }

        return $path !== '' && Digest::isSha256($digest) ? [$path => Digest::of($digest)] : [];
    }
}
