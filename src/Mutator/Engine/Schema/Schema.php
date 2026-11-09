<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function in_array;

use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * A file's mutants compiled into one file (ADR-0091): each site a switch on
 * the line the original stands on, its mutant in force only where the
 * switch's id is on, and the original everywhere else, so every mutant of
 * the file runs the one file. A mutant whose site cannot be switched keeps a
 * file of its own; where the whole cannot be read back as PHP, none is
 * switched.
 *
 * @internal the engine's own
 */
final readonly class Schema
{
    /** @param list<int> $switched the ids of the mutants it switches */
    private function __construct(private string $text, private array $switched)
    {
    }

    /** The schema of a file's code with these sites, each switch this call, `%d` where it takes the site's id. */
    public static function of(string $source, SwitchCall $switch, Site ...$sites): self
    {
        $placed = [];

        foreach ($sites as $site) {
            $kind = self::kindOf($site, $switch);
            $mutated = $kind instanceof SiteKind ? OneLine::of($site->replacement(), $kind) : NotGiven::value();

            if ($kind instanceof SiteKind && ! $mutated instanceof NotGiven) {
                $placed[] = Placed::of($site, $kind, $mutated);
            }
        }

        $spliced = Spliced::of($source, $switch, ...$placed);
        $text = $spliced->text();

        return self::parses($text) ? new self($text, $spliced->switched()) : new self($source, []);
    }

    /** The file's code with its sites switched. */
    public function text(): string
    {
        return $this->text;
    }

    /** Whether it switches the mutant this id names. */
    public function switches(int $id): bool
    {
        return in_array($id, $this->switched, strict: true);
    }

    /** Whether it switches any mutant. */
    public function isEmpty(): bool
    {
        return $this->switched === [];
    }

    /**
     * Where a site takes its place: as Placement says, and a pure expression
     * by a call that takes both values, where the switches ask for one.
     */
    private static function kindOf(Site $site, SwitchCall $switch): SiteKind|NotGiven
    {
        $kind = Placement::of($site);
        $replacement = $site->replacement();

        return $kind === SiteKind::Expression
            && $switch->values()
            && $replacement instanceof Node
            && PureExpression::is($site->original())
            && PureExpression::is($replacement)
            ? SiteKind::Value
            : $kind;
    }

    private static function parses(string $text): bool
    {
        try {
            new ParserFactory()->createForNewestSupportedVersion()->parse($text);

            return true;
        } catch (Error) {
            return false;
        }
    }
}
