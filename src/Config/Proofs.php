<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\StoreOption;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Writing;

/** Where proofs are kept, and what they leave out (ADR-0007): `proofs`. */
final readonly class Proofs implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** A directory; `.mutation-gate/ledger` when no path is given. */
    public static function directory(string|NotGiven $path = new NotGiven()): self
    {
        return self::store(BuiltinStore::Directory->value, ['path' => $path]);
    }

    /** An S3-compatible bucket, such as AWS S3, Cloudflare R2 or MinIO; an option left out takes its default. */
    public static function s3(
        string $bucket,
        string|NotGiven $prefix = new NotGiven(),
        string|NotGiven $region = new NotGiven(),
        string|NotGiven $endpoint = new NotGiven(),
        string|NotGiven $publicUrl = new NotGiven(),
    ): self {
        return self::store(BuiltinStore::S3->value, [
            StoreOption::Bucket->value => $bucket,
            StoreOption::Prefix->value => $prefix,
            StoreOption::Region->value => $region,
            StoreOption::Endpoint->value => $endpoint,
            StoreOption::PublicUrl->value => $publicUrl,
        ]);
    }

    /** A Google Cloud Storage bucket; an option left out takes its default. */
    public static function gcs(
        string $bucket,
        string|NotGiven $prefix = new NotGiven(),
        string|NotGiven $publicUrl = new NotGiven(),
    ): self {
        return self::store(BuiltinStore::Gcs->value, [
            StoreOption::Bucket->value => $bucket,
            StoreOption::Prefix->value => $prefix,
            StoreOption::PublicUrl->value => $publicUrl,
        ]);
    }

    /** A container of an Azure storage account; an option left out takes its default. */
    public static function azure(
        string $account,
        string $container,
        string|NotGiven $prefix = new NotGiven(),
        string|NotGiven $publicContainer = new NotGiven(),
        string|NotGiven $publicUrl = new NotGiven(),
    ): self {
        return self::store(BuiltinStore::Azure->value, [
            StoreOption::Account->value => $account,
            StoreOption::Container->value => $container,
            StoreOption::Prefix->value => $prefix,
            StoreOption::PublicContainer->value => $publicContainer,
            StoreOption::PublicUrl->value => $publicUrl,
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
        return new self(Json::at('proofs.write', Writing::Auto->value));
    }

    /** Nothing is written: the store is read-only. */
    public static function readOnly(): self
    {
        return new self(Json::at('proofs.write', Writing::Never->value));
    }

    public function written(): Json
    {
        return $this->json;
    }

    /** @param array<string, string|NotGiven> $options the options, each left out where it is not given */
    private static function store(string $store, array $options): self
    {
        $given = [];

        foreach ($options as $name => $value) {
            if (is_string($value)) {
                $given[] = Option::of($name, $value);
            }
        }

        return self::uses($store, ...$given);
    }
}
