<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_slice;
use function explode;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\TooLarge;

use function sprintf;

/**
 * A pack of `outbreak cache` (ADR-0131): a gzipped text of lines, the first
 * naming the pack's class and base, each other one entry, a proof under its
 * key written whole as a ledger of that proof alone, so an entry reads as a
 * ledger's proof reads and the binary merges packs by key without reading a
 * proof. A pack past the ledger's limits, of another class or base, or not
 * whole, holds nothing a run takes.
 *
 * @internal the shape of a pack file, which the outbreak binary reads and writes too
 */
final readonly class CachePack
{
    /** The contract the pack is written in, which the binary speaks. */
    public const int FORMAT = 1;

    /** What the first line calls a pack of proofs. */
    public const string PROOFS = 'proofs';

    /** The file a delivery holds the entries pushed in. */
    public const string DELIVERED = 'cache.pack';

    private const string LINE = "\n";

    private const string NAMED = 'The proof cache\'s pack';

    private const string OTHER = 'The proof cache\'s pack is of another class, base or format, so the run takes none of it.';

    private const string UNREAD = 'The proof cache\'s pack holds an entry this gate does not read: %s';

    /** The pack's bytes: these proofs of this class, at this base, those of another base left out. */
    public static function bytes(CacheClass $class, Digest $base, Proofs $proofs): string
    {
        $lines = [self::header($class, $base)->line()];

        foreach ($proofs as $proof) {
            if ($proof->run()->base()->value() !== $base->value()) {
                continue;
            }

            $lines[] = Json::object(
                Member::of('key', $proof->key()->value()),
                Member::of('ledger', ProofEntry::text($proof)),
            )->line();
        }

        return Gzip::packAt(implode(self::LINE, $lines) . self::LINE, LedgerCodec::LEVEL);
    }

    /** The proofs a pack of this class at this base holds, within the ledger's limits; or why it holds none. */
    public static function read(string $bytes, CacheClass $class, Digest $base, LedgerLimits $limits): Proofs|CannotJudge
    {
        $text = $limits->admitsPacked(Bytes::length($bytes))
            ? Gzip::unpackAtMost($bytes, self::NAMED, $limits->unpacked())
            : TooLarge::because($limits->pastPacked());

        if (! is_string($text)) {
            return $text instanceof TooLarge ? CannotJudge::because($text->why()) : $text;
        }

        $lines = explode(self::LINE, $text);

        try {
            return self::isOf(Node::decode($lines[0]), $class, $base)
                ? self::proofsIn(array_slice($lines, 1))
                : CannotJudge::because(self::OTHER);
        } catch (NotInShape $notInShape) {
            return CannotJudge::because(sprintf(self::UNREAD, $notInShape->getMessage()));
        }
    }

    private static function header(CacheClass $class, Digest $base): Json
    {
        return Json::object(
            Member::of('outbreak', self::FORMAT),
            Member::of('pack', self::PROOFS),
            Member::of('class', $class->value),
            Member::of('base', $base->value()),
        );
    }

    private static function isOf(Node $header, CacheClass $class, Digest $base): bool
    {
        return $header->kind() === Kind::Map
            && $header->field('outbreak')->kind() === Kind::Integer
            && $header->field('outbreak')->integer() === self::FORMAT
            && self::says($header, 'pack', self::PROOFS)
            && self::says($header, 'class', $class->value)
            && self::says($header, 'base', $base->value());
    }

    private static function says(Node $header, string $field, string $text): bool
    {
        return $header->field($field)->kind() === Kind::Text && $header->field($field)->text() === $text;
    }

    /**
     * Each entry's proof, under its key.
     *
     * @param list<string> $lines
     *
     * @throws NotInShape
     */
    private static function proofsIn(array $lines): Proofs
    {
        $proofs = Proofs::none();

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $entry = Node::decode($line);
            $key = Digest::of($entry->field('key')->text());
            $proof = ProofEntry::read($key, $entry->field('ledger')->text());

            if (! $proof instanceof Proof) {
                throw NotInShape::at($key->value(), 'a ledger holding the proof under its key');
            }

            $proofs = $proofs->with($proof);
        }

        return $proofs;
    }
}
