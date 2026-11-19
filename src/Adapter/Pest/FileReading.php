<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function hash_update_file;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

use function sprintf;

/**
 * What the suite's reading takes of one loaded test file (see LoadedTests):
 * the names it declares and mentions, as NamedFiles links files by them,
 * and whether loading it is inert (see Registration); by the digest of the
 * text it was read from.
 *
 * @internal
 */
final readonly class FileReading
{
    /**
     * @param list<string> $declares
     * @param list<string> $mentions
     */
    private function __construct(
        public string $digest,
        public array $declares,
        public array $mentions,
        public bool $inert,
    ) {
    }

    /** The file's text, read. */
    public static function of(string $text): self
    {
        $read = PhpFile::read(Contents::of($text));

        return new self(
            self::digestOf($text),
            NamedFiles::declaredIn($read),
            NamedFiles::mentionedIn($read),
            Registration::inert($read),
        );
    }

    /** What tells this text from any other, as a kept reading is matched to the file by it. */
    public static function digestOf(string $text): string
    {
        return hash(Digest::QUICK, $text);
    }

    /** What tells these files, each by its path and text, from any others. */
    public static function digestOfFiles(string ...$paths): string
    {
        $digest = hash_init(Digest::QUICK);

        foreach ($paths as $path) {
            hash_update($digest, sprintf("%s\n", $path));
            hash_update_file($digest, $path);
        }

        return hash_final($digest);
    }

    /**
     * A reading as encoded() wrote it.
     *
     * @throws NotInShape
     */
    public static function decoded(Node $node): self
    {
        return new self(
            $node->field('digest')->text(),
            self::texts($node->field('declares')),
            self::texts($node->field('mentions')),
            $node->field('inert')->boolean(),
        );
    }

    /** @return array{digest: string, declares: list<string>, mentions: list<string>, inert: bool} */
    public function encoded(): array
    {
        return [
            'digest' => $this->digest,
            'declares' => $this->declares,
            'mentions' => $this->mentions,
            'inert' => $this->inert,
        ];
    }

    /**
     * Each text of a list as encoded() wrote it.
     *
     * @return list<string>
     *
     * @throws NotInShape
     */
    private static function texts(Node $node): array
    {
        return array_map(static fn(Node $item): string => $item->text(), $node->items());
    }
}
