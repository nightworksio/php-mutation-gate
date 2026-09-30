<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_find;
use function array_map;

use BackedEnum;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\Series;

use function sprintf;

/**
 * One of a closed set of words, read into the enum case it names.
 *
 * @template-covariant T of BackedEnum
 *
 * @implements Shape<T>
 */
final readonly class Enumerated implements Shape
{
    /** @param list<T> $cases */
    private function __construct(private array $cases)
    {
    }

    /**
     * @template U of BackedEnum
     *
     * @param  list<U>  $cases
     * @return self<U>
     */
    public static function of(array $cases): self
    {
        return new self($cases);
    }

    public function read(Node $at): Reading
    {
        $word = $at->kind() === Kind::Text ? $at->text() : '';
        $case = array_find($this->cases, static fn(BackedEnum $case): bool => $case->value === $word);

        return $case instanceof BackedEnum ? Reading::of($case) : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        $quoted = array_map(static fn(BackedEnum $case): string => sprintf('"%s"', $case->value), $this->cases);

        return Series::or(...$quoted);
    }

    public function schema(): Json
    {
        return Json::object(
            Member::of(
                'enum',
                Json::items(...array_map(
                    static fn(BackedEnum $case): int|string => $case->value,
                    $this->cases,
                )),
            ),
        );
    }

    public function effects(): array
    {
        return [];
    }
}
