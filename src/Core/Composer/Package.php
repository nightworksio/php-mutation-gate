<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

/**
 * A package the gate names, as Composer names it: a runner or an analyser it
 * drives or detects, a package whose version decides how a runner judges, or
 * a framework that says which preset fits.
 */
enum Package: string
{
    case Pest = 'pestphp/pest';
    case PestMutate = 'pestphp/pest-plugin-mutate';
    case Infection = 'infection/infection';
    case PhpUnit = 'phpunit/phpunit';
    case CodeCoverage = 'phpunit/php-code-coverage';
    case PhpStan = 'phpstan/phpstan';
    case Mago = 'carthage-software/mago';
    case Laravel = 'laravel/framework';
    case Symfony = 'symfony/framework-bundle';
}
