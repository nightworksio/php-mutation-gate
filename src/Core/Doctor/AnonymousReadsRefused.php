<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

/**
 * An Azure storage account that refused an anonymous read of the public
 * container the `azure` store names, as one whose `AllowBlobPublicAccess`
 * is off does (ADR-0028 decision 4).
 */
final readonly class AnonymousReadsRefused
{
    private function __construct(private string $account, private string $publicUrl)
    {
    }

    public static function by(string $account, string $publicUrl): self
    {
        return new self($account, $publicUrl);
    }

    public function account(): string
    {
        return $this->account;
    }

    /** The URL a job without credentials reads the default branch's ledger from. */
    public function publicUrl(): string
    {
        return $this->publicUrl;
    }
}
