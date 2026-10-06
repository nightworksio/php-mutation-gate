<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Why a warm worker forks nothing, so its runs are left to fresh processes
 * (ADR-0023, decisions 13 and 14): a boot the guard refused, which the run
 * warns of, or a PHP that cannot fork, which leaves `runner.workers` at
 * `fresh` as its default does and warns of nothing.
 */
final readonly class Refusal
{
    private function __construct(private string $reason, private bool $warns)
    {
    }

    /** A boot the guard refused, for this reason. */
    public static function guarded(string $reason): self
    {
        return new self($reason, warns: true);
    }

    /** A PHP that cannot fork, for this reason. */
    public static function unforkable(string $reason): self
    {
        return new self($reason, warns: false);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** Whether the run warns of it. */
    public function warns(): bool
    {
        return $this->warns;
    }

    public function writtenTo(string $file): void
    {
        file_put_contents(
            $file,
            Json::object(Member::of('reason', $this->reason), Member::of('warns', $this->warns))->line(),
        );
    }

    /** The refusal a worker wrote, or none, where it wrote none or its file is not one. */
    public static function readFrom(string $file): self|NotGiven
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        try {
            $at = is_string($text) ? Node::decode($text) : NotGiven::value();

            return $at instanceof Node
                ? new self($at->field('reason')->text(), warns: $at->field('warns')->boolean())
                : NotGiven::value();
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }
}
