<?php

declare(strict_types=1);

/*
 * The gate's PHAR scopes its dependencies under a fixed prefix (ADR-0022). The gate's namespaces, Psr\Clock and
 * PhpParser stay as they are, since extensions and mutator sets implement them, and so do the packages a project
 * brings for the gate's adapters to drive. Two kinds of the gate's files stay as they are too: those that run in a
 * project's own process, among the project's packages, its scripts included, and those that write PHP code for
 * another process to run.
 */

$writesCode = static fn(string $file): bool => array_any(
    PhpToken::tokenize((string) file_get_contents($file)),
    static fn(PhpToken $token): bool => $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])
        && str_contains($token->text, '<?php'),
);

$templates = [];

foreach (['src', 'plugins/default/src', 'plugins/laravel/src', 'plugins/security/src', 'plugins/symfony/src'] as $tree) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/' . $tree, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && $writesCode($file->getPathname())) {
            $templates[] = $file->getPathname();
        }
    }
}

return [
    'prefix' => 'NightWorksIOMutationGateScoped',
    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
    'exclude-namespaces' => [
        'NightWorksIO\MutationGate',
        'NightWorksIO\MutationGateDefault',
        'NightWorksIO\MutationGateLaravel',
        'NightWorksIO\MutationGateSecurity',
        'NightWorksIO\MutationGateSymfony',
        'Psr\Clock',
        'PhpParser',
        'PHPUnit',
        'SebastianBergmann',
        'Pest',
        'PhpSpec',
        'Codeception',
        'Infection',
        'AsyncAws',
        'Nette',
        'ColinODell',
        'Symfony\Component\EventDispatcher',
        'Symfony\Component\Yaml',
    ],
    'exclude-files' => [
        ...$templates,
        __DIR__ . '/bin/mutation-gate-pest-client',
        __DIR__ . '/bin/mutation-gate-pest-worker',
        __DIR__ . '/bin/mutation-gate-phpspec',
        __DIR__ . '/bin/mutation-gate-worker',
        __DIR__ . '/src/Adapter/Infection/Silence.php',
        __DIR__ . '/src/Adapter/PhpSpec/Covering.php',
        __DIR__ . '/src/Adapter/PhpSpec/Launch.php',
        __DIR__ . '/src/Adapter/PhpSpec/Listing.php',
        __DIR__ . '/src/Adapter/PhpSpec/Recording.php',
    ],
];
