<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use NightWorksIO\MutationGate\Core\Config\BuilderClasses;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;

use function preg_match;
use function sprintf;

/**
 * A config written as a `mutation-gate.php` (ADR-0002): `Gate::configure()`
 * and one call for each setting it holds, which reads back into the same
 * config.
 */
final readonly class Php
{
    public static function render(PhpCalls $calls): string
    {
        $code = $calls->code();
        $imports = '';

        foreach (BuilderClasses::CLASSES as $class => $namespace) {
            $used = $class === 'Gate' || preg_match(sprintf('/\\b%s::/', $class), $code) === 1;
            $imports = $used ? sprintf("%suse %s\\%s;\n", $imports, $namespace, $class) : $imports;
        }

        return sprintf("<?php\n\ndeclare(strict_types=1);\n\n%s\nreturn Gate::configure()%s;\n", $imports, $code);
    }

}
