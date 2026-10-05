<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a run that ran the project's code leaves for `deliver`, which holds the credentials and runs none of that
 * code (ADR-0007 decision 5): payloads only, each ready to send. Where each goes, and with which credential, is
 * `deliver`'s own environment's to say, never the delivery's.
 */
final readonly class Delivery
{
    /** @param list<AlertPost> $alerts */
    private function __construct(
        private LedgerPost|NotGiven $ledger,
        private string|NotGiven $comment,
        private array $alerts,
        private OtlpPost|NotGiven $otlp,
    ) {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), [], NotGiven::value());
    }

    /** This delivery, with the ledger to write. */
    public function withLedger(LedgerPost $ledger): self
    {
        return new self($ledger, $this->comment, $this->alerts, $this->otlp);
    }

    /** This delivery, with the pull request comment's markdown. */
    public function withComment(string $markdown): self
    {
        return new self($this->ledger, $markdown, $this->alerts, $this->otlp);
    }

    /** This delivery, with one alert more. */
    public function withAlert(AlertPost $alert): self
    {
        return new self($this->ledger, $this->comment, [...$this->alerts, $alert], $this->otlp);
    }

    /** This delivery, with the OTLP export. */
    public function withOtlp(OtlpPost $otlp): self
    {
        return new self($this->ledger, $this->comment, $this->alerts, $otlp);
    }

    public function ledger(): LedgerPost|NotGiven
    {
        return $this->ledger;
    }

    public function comment(): string|NotGiven
    {
        return $this->comment;
    }

    /** @return list<AlertPost> */
    public function alerts(): array
    {
        return $this->alerts;
    }

    public function otlp(): OtlpPost|NotGiven
    {
        return $this->otlp;
    }
}
