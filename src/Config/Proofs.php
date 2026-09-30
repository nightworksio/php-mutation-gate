<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function array_filter;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** Where proofs are kept, and what they leave out (ADR-0007): `proofs`. */
final readonly class Proofs implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** A directory; `.mutation-gate/ledger` when no path is given. */
    public static function directory(string $path = ''): self
    {
        return self::store('directory', ['path' => $path]);
    }

    /** An S3-compatible bucket, such as AWS S3, Cloudflare R2 or MinIO; an option left empty takes its default. */
    public static function s3(
        string $bucket,
        string $prefix = '',
        string $region = '',
        string $endpoint = '',
        string $publicUrl = '',
    ): self {
        return self::store('s3', [
            'bucket' => $bucket,
            'prefix' => $prefix,
            'region' => $region,
            'endpoint' => $endpoint,
            'publicUrl' => $publicUrl,
        ]);
    }

    /** A proof store another extension registers by name, or a class, with its options. */
    public static function uses(string $store, Option ...$options): self
    {
        return new self(Json::object(
            Member::of(
                'proofs',
                Json::object(Member::of('store', Option::choice($store, ...$options))),
            ),
        ));
    }

    /** `proofs.ignore`: the globs of the files no test reads. */
    public static function ignore(string ...$globs): self
    {
        return new self(Json::at('proofs.ignore', Json::items(...$globs)));
    }

    /** The verdict writes the run's own scope. */
    public static function writing(): self
    {
        return new self(Json::at('proofs.write', 'auto'));
    }

    /** Nothing is written: the store is read-only. */
    public static function readOnly(): self
    {
        return new self(Json::at('proofs.write', 'never'));
    }

    public function written(): Json
    {
        return $this->json;
    }

    /** @param array<string, string> $options the options, each left out when it is empty */
    private static function store(string $store, array $options): self
    {
        $given = array_filter($options, static fn(string $value): bool => $value !== '');

        return self::uses($store, ...array_map(Option::of(...), array_keys($given), $given));
    }
}
