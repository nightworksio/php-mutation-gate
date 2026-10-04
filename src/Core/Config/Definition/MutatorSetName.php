<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutatorNamePattern;
use NightWorksIO\MutationGate\Core\Mutant\ShippedMutatorSet;

use function sprintf;

/**
 * The name of a mutator set a config turns on (ADR-0021): lower case letters
 * and digits joined by single hyphens, and never the set this repository
 * ships, which is on in every run.
 *
 * @implements Shape<non-empty-string>
 */
final readonly class MutatorSetName implements Shape
{
    private const string WHAT = "a mutator set's name: lower case letters and digits, joined by single hyphens";

    private const string SHIPPED
        = 'expected a set other than "%s", which is always on: Pest and Infection run their own mutators in its place';

    private function __construct(private Text $name)
    {
    }

    public static function turnedOn(): self
    {
        return new self(Text::matching(self::WHAT, sprintf('^%s$', MutatorNamePattern::SET)));
    }

    public function read(Node $at): Reading
    {
        $reading = $this->name->read($at);

        return $reading->value() === ShippedMutatorSet::NAME
            ? Reading::refused(Problem::at($at->at(), sprintf(self::SHIPPED, ShippedMutatorSet::NAME)))
            : $reading;
    }

    public function expected(): string
    {
        return $this->name->expected();
    }

    public function schema(): Json
    {
        return $this->name->schema()->with(
            Member::of('not', Json::object(Member::of('const', ShippedMutatorSet::NAME))),
        );
    }

    public function effects(): array
    {
        return [];
    }
}
