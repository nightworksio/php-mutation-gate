<?php

declare(strict_types=1);

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\PHPStan\Rules\Mixed\MixedInDocBlock;
use NightWorksIO\MutationGate\PHPStan\Rules\Mixed\MixedInType;
use NightWorksIO\MutationGate\PHPStan\Rules\NoMixedOutsideDecodersRule;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

// D10: the files phpstan.neon still lets hold a mixed are the ones left to
// type, and the list only shrinks. A file that holds none any more, or is gone,
// leaves the list with the change that typed it.

/**
 * The files a rule's argument names in phpstan.neon.
 *
 * @return list<string>
 */
function namedFor(string $rule, string $argument): array
{
    $configuration = Neon::decodeFile(Tree::at('phpstan.neon'));
    $services = is_array($configuration) && is_array($configuration['services']) ? $configuration['services'] : [];
    $named = [];

    foreach ($services as $service) {
        $named = [...$named, ...argumentOf($service, $rule, $argument)];
    }

    return $named;
}

/**
 * A list a service is handed as an argument, where the service is the rule.
 *
 * @return list<string>
 */
function argumentOf(mixed $service, string $rule, string $argument): array
{
    $arguments = is_array($service) && ($service['class'] ?? '') === $rule ? $service['arguments'] ?? [] : [];
    $named = is_array($arguments) ? $arguments[$argument] ?? [] : [];

    return is_array($named) ? array_values(array_filter($named, is_string(...))) : [];
}

/** Whether a file still writes mixed in a native type or a doc block. */
function stillHoldsMixed(string $path): bool
{
    if (! is_file(Tree::at($path))) {
        return false;
    }

    $nodes = new NodeFinder()->findInstanceOf(new ParserFactory()->createForHostVersion()->parse((string) file_get_contents(Tree::at($path))) ?? [], Node::class);

    return array_any(
        $nodes,
        static fn(Node $node): bool => MixedInType::of($node) || ($node->getDocComment() instanceof Doc && MixedInDocBlock::in($node->getDocComment()->getText())),
    );
}

it('reads the files the mixed rule still allows', function (): void {
    expect(namedFor(NoMixedOutsideDecodersRule::class, 'allowIn'))->not->toBe([], 'no allowIn list was read out of phpstan.neon, so nothing was judged');
});

it('allows a mixed only in files that still hold one', function (): void {
    $stale = array_values(array_filter(namedFor(NoMixedOutsideDecodersRule::class, 'allowIn'), static fn(string $path): bool => ! stillHoldsMixed($path)));

    // D10
    expect($stale)->toBe([], sprintf(
        "phpstan.neon allows a mixed in files that hold none:\n  %s\n\nThe list only shrinks: take each one off it (D10).",
        implode("\n  ", $stale),
    ));
});
