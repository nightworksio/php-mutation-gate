<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use function implode;
use function mb_strtoupper;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\StoreOption;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function preg_replace;
use function sprintf;

/**
 * The variables `deliver` and `fetch` read the proof store's whole location from, which the workflow sets and no
 * run of the project's code writes (ADR-0007 decision 5): the store, and each option of where it is. No delivery
 * names any of them, so no run that ran the project's code chooses the bucket, the account or the host a credential
 * goes to.
 */
enum StoreLocation: string
{
    /** The built-in store: `s3`, `gcs` or `azure`. */
    case Store = 'MUTATION_GATE_STORE';

    case Bucket = 'MUTATION_GATE_STORE_BUCKET';
    case Prefix = 'MUTATION_GATE_STORE_PREFIX';
    case Region = 'MUTATION_GATE_STORE_REGION';

    /** An S3-compatible store's endpoint, an `https://` URL: R2's or MinIO's. */
    case Endpoint = 'MUTATION_GATE_STORE_ENDPOINT';

    case Account = 'MUTATION_GATE_STORE_ACCOUNT';
    case Container = 'MUTATION_GATE_STORE_CONTAINER';
    case PublicContainer = 'MUTATION_GATE_STORE_PUBLIC_CONTAINER';

    private const string UNNAMED = '%s names no built-in store that needs credentials: s3, gcs or azure.';

    private const string NOT_HTTPS = '%s is not an https:// URL, so no store is located there.';

    private const string NOT_S3 = '%s is set, and only the s3 store takes an endpoint.';

    /** What an endpoint a credential may go to begins with: `https://` and a host. */
    private const string HTTPS = '#^https://[^/]#';

    /** Where an option's name begins a word of its own. */
    private const string WORD = '#[A-Z]#';

    /**
     * The store these variables locate, with each option they leave unset as the config's definition has it; or
     * why they locate none: no built-in store that needs credentials, an endpoint that is not `https://` or not
     * an S3-compatible store's, or an option the store refuses, by the variable that set it.
     */
    public static function chosen(Variables $environment): Choice|CannotJudge
    {
        $store = BuiltinStore::tryFrom($environment->valueOf(self::Store->value));
        $endpoint = $environment->valueOf(self::Endpoint->value);

        return match (true) {
            ! $store instanceof BuiltinStore || [...$store->variables()] === []
                => CannotJudge::because(sprintf(self::UNNAMED, self::Store->value)),
            $endpoint !== '' && $store !== BuiltinStore::S3
                => CannotJudge::because(sprintf(self::NOT_S3, self::Endpoint->value)),
            $endpoint !== '' && preg_match(self::HTTPS, $endpoint) !== 1
                => CannotJudge::because(sprintf(self::NOT_HTTPS, self::Endpoint->value)),
            default => self::read($store, $environment),
        };
    }

    /** Whether this job holds the credentials the store these variables name needs, as its own variables say. */
    public static function isKeyed(Variables $environment): bool
    {
        $store = BuiltinStore::tryFrom($environment->valueOf(self::Store->value));

        return $store instanceof BuiltinStore && $store->credentials()->heldIn($environment);
    }

    /** The store, with the options these variables set, as the config's definition reads them. */
    private static function read(BuiltinStore $store, Variables $environment): Choice|CannotJudge
    {
        $members = [];

        foreach (self::cases() as $variable) {
            $option = $variable->option();
            $value = $environment->valueOf($variable->value);
            $members = $option instanceof StoreOption && $value !== ''
                ? [...$members, Member::of($option->value, $value)]
                : $members;
        }

        $read = Builtins::stores(ProjectRoot::origin())
            ->choose($store->value, Node::config(Json::object(...$members)->line()));
        $choice = $read->value();
        $problems = [];

        foreach ($read->problems() as $problem) {
            $problems[] = sprintf('%s: %s', self::settingOf($problem->path()), $problem->message());
        }

        return $choice instanceof Choice ? $choice : CannotJudge::because(implode('; ', $problems));
    }

    /** The variable that sets an option, by its name: `publicContainer` is `MUTATION_GATE_STORE_PUBLIC_CONTAINER`. */
    private static function settingOf(string $option): string
    {
        return sprintf('%s_%s', self::Store->value, mb_strtoupper((string) preg_replace(self::WORD, '_$0', $option)));
    }

    /** The store option a variable sets; none for the store itself. */
    private function option(): StoreOption|NotGiven
    {
        return match ($this) {
            self::Store => NotGiven::value(),
            self::Bucket => StoreOption::Bucket,
            self::Prefix => StoreOption::Prefix,
            self::Region => StoreOption::Region,
            self::Endpoint => StoreOption::Endpoint,
            self::Account => StoreOption::Account,
            self::Container => StoreOption::Container,
            self::PublicContainer => StoreOption::PublicContainer,
        };
    }
}
