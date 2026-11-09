<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The entries of a verdict's proofs that `deliver` pushes into their class's
 * pack in a registry (ADR-0131): `cache.pack` beside the delivery, and
 * `cache.json`, the request the verdict wrote: the registry, the base, the
 * class and the limits a pack is held to. `deliver` pushes a trusted pack
 * only from a run that writes the default branch's ledger, and a branch's
 * pack only where the job hands it the registry's token; anything else, and
 * a delivery with no entries, pushes nothing and says nothing.
 *
 * @internal
 */
final readonly class CacheDelivery
{
    public const string ENTRIES = 'cache.pack';

    public const string REQUEST = 'cache.json';

    /** The variable the job hands the registry's token in, which only `deliver` and the binary read. */
    public const string TOKEN = 'MUTATION_GATE_REGISTRY_TOKEN';

    private const string UNTRUSTED = 'This run writes no default branch ledger, so deliver pushes no trusted pack.';

    private const string NO_TOKEN = 'The job hands deliver no registry token, so it pushes no branch pack.';

    private const string UNREAD = 'The proof cache\'s request is not one deliver reads: %s';

    /** The request a verdict leaves for its entries of this class, at this base, for this registry. */
    public static function request(string $registry, Digest $base, CacheClass $class, LedgerLimits $limits): Contents
    {
        return Contents::of(Json::object(
            Member::of('registry', $registry),
            Member::of('base', $base->value()),
            Member::of('class', $class->value),
            Member::of('packed', $limits->packed()),
            Member::of('unpacked', $limits->unpacked()),
        )->line());
    }

    /**
     * The entries a delivery in this directory holds pushed by the binary into
     * their pack, where the run may push their class; or why they were not.
     *
     * @param array<string, string> $environment
     */
    public static function pushed(
        Outbreak $binary,
        string $from,
        bool $trusted,
        array $environment,
    ): Written|NotWritten|NotGiven {
        $file = sprintf('%s/%s', $from, self::REQUEST);
        $request = is_file($file) ? file_get_contents($file) : false;

        if (! is_string($request)) {
            return NotGiven::value();
        }

        try {
            $read = Node::decode($request);
            $class = CacheClass::tryFrom($read->field('class')->text())
                ?? throw NotInShape::at('class', 'trusted or branch');
            $asked = CacheRequest::to(
                CacheStore::Oci,
                $read->field('registry')->text(),
                Digest::of($read->field('base')->text()),
                LedgerLimits::of(
                    $read->field('packed')->integer(),
                    $read->field('unpacked')->integer(),
                    LedgerLimits::SECONDS,
                ),
            )->for($class, Path::of(sprintf('%s/%s', $from, self::ENTRIES)), Path::of($from));
        } catch (NotInShape $notInShape) {
            return NotWritten::because(sprintf(self::UNREAD, $notInShape->getMessage()));
        }

        return match (true) {
            $class === CacheClass::Trusted && ! $trusted => NotWritten::because(self::UNTRUSTED),
            $class === CacheClass::Branch && ! is_string($environment[self::TOKEN] ?? null)
                => NotWritten::because(self::NO_TOKEN),
            default => $binary->pushed($asked),
        };
    }
}
