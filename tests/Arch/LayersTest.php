<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Layer;
use NightWorksIO\MutationGate\Tests\Support\Source;

// A1–A5, over the class names each file under src writes, imports included.
// Read with the parser rather than with Pest's architecture expectations, which
// raise on a namespace that holds no class yet.

/**
 * Every name a file under a layer writes that the given judgement refuses, as
 * "path names class".
 *
 * @param  Closure(Source, string): bool $refused
 * @return list<string>
 */
function namesRefusedIn(Layer $layer, Closure $refused): array
{
    $found = [];

    foreach (Source::under($layer->directory()) as $source) {
        foreach ($source->names() as $name) {
            if ($refused($source, $name)) {
                $found[] = sprintf('%s names %s', $source->path, $name);
            }
        }
    }

    return $found;
}

/** Whether a name belongs to PHP itself: a class in the global namespace. */
function isPhpItself(string $name): bool
{
    return ! str_contains($name, '\\');
}

it('keeps the core, the ports, the config and the extension API free of every framework, and the mutator SDK of all but php-parser', function (): void {
    $inner = [Layer::Core, Layer::Port, Layer::Mutator, Layer::Config, Layer::Extension];
    $offenders = [];

    foreach ($inner as $layer) {
        $offenders = [...$offenders, ...namesRefusedIn($layer, static fn(Source $source, string $name): bool => ! isPhpItself($name)
            && ! str_starts_with($name, sprintf('%s\\', Layer::ROOT))
            && ! str_starts_with($name, 'Psr\\Clock\\')
            && ($layer !== Layer::Mutator || !str_starts_with($name, 'PhpParser\\')))];
    }

    // A1
    expect($offenders)->toBe([], sprintf(
        "These name something outside the package, PHP and Psr\\Clock, or, in the mutator SDK, php-parser:\n  %s\n\nThe core decides, and a decision that names a framework cannot be tested without it. Name a port and let an adapter reach the library; only the SDK a mutator is written against names php-parser (A1).",
        implode("\n  ", $offenders),
    ));
});

it('declares nothing but interfaces as ports', function (): void {
    $offenders = [];

    foreach (Source::under(Layer::Port->directory()) as $source) {
        if (! $source->declaresOnlyInterfaces()) {
            $offenders[] = $source->path;
        }
    }

    // A2
    expect($offenders)->toBe([], sprintf(
        "These are under src/Port and are not interfaces:\n  %s\n\nA port is what the core asks; what answers it is an adapter or a core value (A2).",
        implode("\n  ", $offenders),
    ));
});

it('keeps every adapter apart from every other', function (): void {
    $offenders = namesRefusedIn(Layer::Adapter, static function (Source $source, string $name): bool {
        if (! Layer::Adapter->holds($name)) {
            return false;
        }

        $own = explode('\\', mb_substr($source->namespace(), mb_strlen(Layer::Adapter->namespace()) + 1))[0];

        return ! str_starts_with($name, sprintf('%s\\%s\\', Layer::Adapter->namespace(), $own));
    });

    // A3
    expect($offenders)->toBe([], sprintf(
        "These adapters name another adapter:\n  %s\n\nAn adapter adapts one outside thing. What two of them share belongs in the core, and the CLI is what puts them side by side (A3).",
        implode("\n  ", $offenders),
    ));
});

it('lets a layer name only itself and the layers before it', function (): void {
    $order = Layer::cases();
    $offenders = [];

    foreach ($order as $at => $layer) {
        $later = array_slice($order, $at + 1);
        $offenders = [...$offenders, ...namesRefusedIn($layer, static fn(Source $source, string $name): bool => array_any(
            $later,
            static fn(Layer $after): bool => $after->holds($name),
        ))];
    }

    // A4
    expect($offenders)->toBe([], sprintf(
        "These name a layer that comes after their own:\n  %s\n\nThe layers are Core, Attribute, Port, Mutator, Config, Extension, Adapter and Cli, in that order, so only the CLI wires adapters to the core (A4).",
        implode("\n  ", $offenders),
    ));
});

it('keeps the attribute to PHP alone, and named by nothing else under src', function (): void {
    $offenders = namesRefusedIn(
        Layer::Attribute,
        static fn(Source $source, string $name): bool => ! isPhpItself($name),
    );

    foreach (Layer::cases() as $layer) {
        $offenders = $layer === Layer::Attribute ? $offenders : [
            ...$offenders,
            ...namesRefusedIn(
                $layer,
                static fn(Source $source, string $name): bool => Layer::Attribute->holds($name)
                    && ! str_ends_with($source->path, 'src/Adapter/Pest/Grouping/HoldsGroups.php'),
            ),
        ];
    }

    // A5
    expect($offenders)->toBe([], sprintf(
        "These cross the attribute's boundary:\n  %s\n\nTests write #[Holds], the gate reads its tokens, and only the Pest plugin's filter reflects on it (A5).",
        implode("\n  ", $offenders),
    ));
});
