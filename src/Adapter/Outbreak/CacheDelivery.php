<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Ci\Variables;
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
use NightWorksIO\MutationGate\Core\Proof\CachePack;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Written;

use function preg_match;
use function sprintf;

/**
 * The packs of proofs a job that runs none of the project's code moves to and
 * from a registry (ADR-0131). The registry is the one the workflow names in
 * `MUTATION_GATE_REGISTRY`, a plain `host[:port]/path`, and never one a run's
 * files name: `fetch` pulls each class's newest pack from it before the plan,
 * and `deliver` pushes a verdict's entries, `cache.pack`, beside the request
 * the verdict wrote, `cache.json`, only where the request names that very
 * registry. The limits a pack is held to are the gate's own. A trusted pack is
 * pushed only from a run that writes the default branch's ledger, and a
 * branch's only where the job hands the registry's token.
 *
 * @internal
 */
final readonly class CacheDelivery
{
    /** The variable the workflow names the registry in, from its own trusted settings. */
    public const string REGISTRY = 'MUTATION_GATE_REGISTRY';

    /** The variable the job hands the registry's token in, which only the binary reads. */
    public const string TOKEN = 'MUTATION_GATE_REGISTRY_TOKEN';

    /** Where `fetch` leaves each class's newest pack, under the directory it fetches into. */
    public const string PULLED = 'cache/%s/%s.pack';

    /** A repository of a registry as a plain `host[:port]/path`, with no scheme, user, query or `..`. */
    private const string REPOSITORY = '~^[a-z0-9.-]+(?::[0-9]{1,5})?/(?!/)(?!.*\.\.)[a-z0-9._/-]+$~D';

    private const string NO_REGISTRY = 'The workflow names no registry in MUTATION_GATE_REGISTRY, so no proof pack moves.';

    private const string UNSAFE = 'MUTATION_GATE_REGISTRY is no plain host[:port]/path, so no proof pack moves.';

    private const string ELSEWHERE = 'The proof cache\'s request names another registry than the workflow\'s, so deliver pushes nothing.';

    private const string UNTRUSTED = 'This run writes no default branch ledger, so deliver pushes no trusted pack.';

    private const string NO_TOKEN = 'The job hands deliver no registry token, so it pushes no branch pack.';

    private const string UNREAD = 'The proof cache\'s request is not one deliver reads: %s';

    /** The request a verdict leaves for its entries of this class, at this base, for this registry. */
    public static function request(string $registry, Digest $base, CacheClass $class): Contents
    {
        return Contents::of(Json::object(
            Member::of('registry', $registry),
            Member::of('base', $base->value()),
            Member::of('class', $class->value),
        )->line());
    }

    /** Whether a text names a repository of a registry as a plain `host[:port]/path`. */
    public static function isRepository(string $named): bool
    {
        return preg_match(self::REPOSITORY, $named) === 1;
    }

    /**
     * The registry the workflow names, where it is a plain repository; why no
     * pack moves otherwise.
     */
    public static function registryIn(Variables $environment): string|NotWritten
    {
        $named = $environment->valueOf(self::REGISTRY);

        return match (true) {
            $named === '' => NotWritten::because(self::NO_REGISTRY),
            ! self::isRepository($named) => NotWritten::because(self::UNSAFE),
            default => $named,
        };
    }

    /**
     * Each class's newest pack pulled from the workflow's registry into the
     * directory `fetch` fetches into, which the binary is rooted at, for the
     * plan to read; why none was.
     */
    public static function pulled(Outbreak $binary, Variables $environment): Written|NotWritten
    {
        $registry = self::registryIn($environment);

        if (! is_string($registry)) {
            return $registry;
        }

        $request = CacheRequest::to(CacheStore::Oci, $registry, Digest::ofNothing(), LedgerLimits::standard())->newest();

        foreach (CacheClass::cases() as $class) {
            $request = $request->for(
                $class,
                Path::of(sprintf(self::PULLED, $class->value, $class->value)),
                Path::of(sprintf(self::PULLED, $class->value, $class->value)),
            );
        }

        return $binary->pulled($request);
    }

    /**
     * The entries a delivery in this directory, which the binary is rooted
     * at, holds pushed by the binary into their pack in the workflow's
     * registry, where the run may push their class and the request names
     * that registry; or why they were not.
     */
    public static function pushed(
        Outbreak $binary,
        string $from,
        bool $trusted,
        Variables $environment,
    ): Written|NotWritten|NotGiven {
        $file = sprintf('%s/%s', $from, CacheRequest::DELIVERED);
        $request = is_file($file) ? file_get_contents($file) : false;

        if (! is_string($request)) {
            return NotGiven::value();
        }

        $registry = self::registryIn($environment);

        if (! is_string($registry)) {
            return $registry;
        }

        try {
            $read = Node::decode($request);
            $named = $read->field('registry')->text();
            $base = $read->field('base')->text();
            $class = CacheClass::tryFrom($read->field('class')->text())
                ?? throw NotInShape::at('class', 'trusted or branch');
        } catch (NotInShape $notInShape) {
            return NotWritten::because(sprintf(self::UNREAD, $notInShape->getMessage()));
        }

        return match (true) {
            $named !== $registry => NotWritten::because(self::ELSEWHERE),
            ! Digest::isSha256($base) => NotWritten::because(sprintf(self::UNREAD, 'its base is no digest')),
            $class === CacheClass::Trusted && ! $trusted => NotWritten::because(self::UNTRUSTED),
            $class === CacheClass::Branch && $environment->valueOf(self::TOKEN) === ''
                => NotWritten::because(self::NO_TOKEN),
            default => $binary->pushed(
                CacheRequest::to(CacheStore::Oci, $registry, Digest::of($base), LedgerLimits::standard())
                    ->for($class, Path::of(CachePack::DELIVERED), Path::of('.')),
            ),
        };
    }
}
