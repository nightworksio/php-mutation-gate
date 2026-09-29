<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Api;
use NightWorksIO\MutationGate\Tests\Support\Layer;

// C1, C2, D1, D2 and D3, over the API surface: every class in Attribute, Port,
// Config and Extension, and every core type their public signatures reach; and
// C2 again over every class in the core. Read by reflection, because a nullable
// return or a primitive parameter is not a name an import shows.

/** The primitives D2 keeps to named constructors. */
const PRIMITIVES = ['string', 'int', 'float'];

it('lets no port answer with nothing', function (): void {
    $offenders = [];

    foreach (Api::classesUnder(Layer::Port->directory()) as $port) {
        foreach (Api::publicMethodsOf($port) as $method) {
            if (Api::returnNames($method) === ['void']) {
                $offenders[] = Api::describe($method);
            }
        }
    }

    // C1
    expect($offenders)->toBe([], sprintf(
        "These ports answer with nothing:\n  %s\n\nA port that answers void can only refuse by throwing, and an exception crosses the port unseen. Answer with a value or an outcome the core has to open (C1).",
        implode("\n  ", $offenders),
    ));
});

it('passes no null across the public API', function (): void {
    $offenders = [];

    foreach (Api::surface() as $class) {
        foreach (Api::publicMethodsOf($class) as $method) {
            if (in_array('null', Api::returnNames($method), strict: true)) {
                $offenders[] = sprintf('%s answers with null', Api::describe($method));
            }

            foreach ($method->getParameters() as $parameter) {
                if (in_array('null', Api::parameterNames($parameter), strict: true)) {
                    $offenders[] = sprintf('%s takes null as $%s', Api::describe($method), $parameter->getName());
                }
            }
        }
    }

    // C2
    expect($offenders)->toBe([], sprintf(
        "These pass null across the public API:\n  %s\n\nA null means absence without saying which absence. Answer with a type that names it (C2).",
        implode("\n  ", $offenders),
    ));
});

it('lets nothing in the core be null', function (): void {
    $offenders = [];

    foreach (Api::classesUnder(Layer::Core->directory()) as $class) {
        foreach (Api::methodsOf($class) as $method) {
            if (in_array('null', Api::returnNames($method), strict: true)) {
                $offenders[] = sprintf('%s answers with null', Api::describe($method));
            }

            foreach ($method->getParameters() as $parameter) {
                if (in_array('null', Api::parameterNames($parameter), strict: true)) {
                    $offenders[] = sprintf('%s takes null as $%s', Api::describe($method), $parameter->getName());
                }
            }
        }

        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class->getName() && in_array('null', Api::propertyNames($property), strict: true)) {
                $offenders[] = sprintf('%s::$%s can hold null', $class->getName(), $property->getName());
            }
        }
    }

    // C2
    expect($offenders)->toBe([], sprintf(
        "These in the core can be null:\n  %s\n\nThe core decides, and a null it decides with means absence without saying which. Absence is a type or an outcome (C2).",
        implode("\n  ", $offenders),
    ));
});

it('passes no array across the public API', function (): void {
    $offenders = [];

    foreach (Api::surface() as $class) {
        foreach (Api::publicMethodsOf($class) as $method) {
            if (in_array('array', Api::returnNames($method), strict: true)) {
                $offenders[] = sprintf('%s answers with an array', Api::describe($method));
            }

            foreach ($method->getParameters() as $parameter) {
                if (in_array('array', Api::parameterNames($parameter), strict: true)) {
                    $offenders[] = sprintf('%s takes an array as $%s', Api::describe($method), $parameter->getName());
                }
            }
        }
    }

    // D1
    expect($offenders)->toBe([], sprintf(
        "These pass an array across the public API:\n  %s\n\nAn array's shape lives in a docblock an extension author's analyser may not read. Pass a value or a typed collection (D1).",
        implode("\n  ", $offenders),
    ));
});

it('takes a primitive only in a named constructor on the public API', function (): void {
    $offenders = [];

    foreach (Api::surface() as $class) {
        foreach (Api::publicMethodsOf($class) as $method) {
            // PHP builds an attribute by calling its constructor with the arguments the attribute is written with.
            if ($method->isStatic() || ($method->isConstructor() && $class->getAttributes(Attribute::class) !== [])) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                if (array_intersect(Api::parameterNames($parameter), PRIMITIVES) !== []) {
                    $offenders[] = sprintf('%s takes a primitive as $%s', Api::describe($method), $parameter->getName());
                }
            }
        }
    }

    // D2
    expect($offenders)->toBe([], sprintf(
        "These take a string, int or float outside a named constructor:\n  %s\n\nA path, a floor and a duration are types. Take the type, and build it from the primitive in a static named constructor where the primitive is checked once (D2).",
        implode("\n  ", $offenders),
    ));
});

it('says what every value on the public API is', function (): void {
    $offenders = [];

    foreach (Api::surface() as $class) {
        foreach (Api::publicMethodsOf($class) as $method) {
            if (in_array('mixed', Api::returnNames($method), strict: true)) {
                $offenders[] = sprintf('%s answers with mixed', Api::describe($method));
            }

            foreach ($method->getParameters() as $parameter) {
                if (in_array('mixed', Api::parameterNames($parameter), strict: true)) {
                    $offenders[] = sprintf('%s takes mixed as $%s', Api::describe($method), $parameter->getName());
                }
            }
        }
    }

    // D3
    expect($offenders)->toBe([], sprintf(
        "These say mixed on the public API:\n  %s\n\nType coverage counts mixed as a type; it is the absence of one. Name what arrives (D3).",
        implode("\n  ", $offenders),
    ));
});
