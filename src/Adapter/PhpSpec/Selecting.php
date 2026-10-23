<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use NightWorksIO\MutationGate\Core\Native\Selected;
use PhpSpec\Loader\Node\ExampleNode;
use PhpSpec\Runner\CollaboratorManager;
use PhpSpec\Runner\Maintainer\Maintainer;
use PhpSpec\Runner\MatcherManager;
use PhpSpec\Specification;

/**
 * Skips each example the run does not select, before anything of it runs:
 * the first of PhpSpec's maintainers to prepare an example, so neither its
 * `let` nor its collaborators run. PhpSpec dispatches `beforeExample`
 * outside the example's own handling, where an exception breaks the run,
 * so the skip is a maintainer's.
 */
final readonly class Selecting implements Maintainer
{
    /** Above PhpSpec's own, the highest of which prepares at 999. */
    private const int FIRST = 1000;

    public function __construct(private Selected $selected)
    {
    }

    public function teardown(
        ExampleNode $example,
        Specification $context,
        MatcherManager $matchers,
        CollaboratorManager $collaborators,
    ): void {
    }

    public function supports(ExampleNode $example): bool
    {
        return true;
    }

    public function prepare(
        ExampleNode $example,
        Specification $context,
        MatcherManager $matchers,
        CollaboratorManager $collaborators,
    ): void {
        if (! $this->selected->selects(Example::of($example))) {
            throw NotSelected::byTheRun();
        }
    }

    public function getPriority(): int
    {
        return self::FIRST;
    }
}
