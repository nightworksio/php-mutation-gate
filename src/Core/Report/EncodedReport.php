<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * A verdict's JSON report, built and encoded once (ADR-0009): its data,
 * which the HTML page renders, and its text, which the kept copy, the `json`
 * report and the digest write and read.
 *
 * @internal
 *
 * @phpstan-import-type Report from JsonReport
 */
final readonly class EncodedReport
{
    /** @param Report $data */
    private function __construct(private array $data, private string $text)
    {
    }

    public static function of(Verdict $verdict): self
    {
        $data = JsonReport::of($verdict);

        return new self($data, JsonText::encode($data));
    }

    /** @return Report */
    public function data(): array
    {
        return $this->data;
    }

    public function text(): string
    {
        return $this->text;
    }
}
