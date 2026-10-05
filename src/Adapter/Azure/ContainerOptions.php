<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Required;
use NightWorksIO\MutationGate\Core\Config\StoreName;
use NightWorksIO\MutationGate\Core\Config\StoreOption;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What the `azure` store's options say, as the definition reads them with
 * their defaults: the storage `account`, the private `container`, the
 * `prefix`, the `publicContainer` the default branch's scope is kept in, and
 * the `publicUrl` a job without credentials reads it from (ADR-0028
 * decisions 1 and 4). It has no option for an account key or a shared access
 * signature, so a config that gives one names a key the store does not know.
 */
final readonly class ContainerOptions
{
    private function __construct(
        private string $account,
        private string $container,
        private string $prefix,
        private string|NotGiven $publicContainer,
        private string|NotGiven $publicUrl,
    ) {
    }

    public static function read(Options $options): self|Invalid
    {
        $account = Required::named($options, StoreOption::Account, StoreName::AzureAccount);
        $container = Required::named($options, StoreOption::Container, StoreName::AzureContainer);
        $prefix = Required::text($options, StoreOption::Prefix->value);
        $publicContainer = StoreName::AzureContainer->in($options, StoreOption::PublicContainer);
        $publicUrl = $options->text(Key::of(StoreOption::PublicUrl->value));

        return match (true) {
            $account instanceof Problem => Invalid::because($account),
            $container instanceof Problem => Invalid::because($container),
            $prefix instanceof Problem => Invalid::because($prefix),
            $publicContainer instanceof Problem => Invalid::because($publicContainer),
            $publicUrl instanceof Problem => Invalid::because($publicUrl),
            default => new self($account, $container, $prefix, $publicContainer, $publicUrl),
        };
    }

    public function account(): string
    {
        return $this->account;
    }

    public function container(): string
    {
        return $this->container;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** The container the default branch's scope is kept in, which anyone may read; none where none is named. */
    public function publicContainer(): string|NotGiven
    {
        return $this->publicContainer;
    }

    /** The URL a job without credentials reads the default branch's ledger from; none where none is named. */
    public function publicUrl(): string|NotGiven
    {
        return $this->publicUrl;
    }
}
