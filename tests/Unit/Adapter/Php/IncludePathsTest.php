<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\IncludePaths;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;

it('finds a path in a project whose root is written with a slash at its end', function (): void {
    expect(IncludePaths::in('/no/such/project/')->inProject('/no/such/project/config.php'))->toBe('config.php');
});

it('looks for a path that climbs out of the working directory there alone', function (): void {
    expect(IncludePaths::in('/no/such/project')->candidates('../shared.php', '/no/such/project/config/app.php'))
        ->toBe(['/no/such/shared.php']);
});

it('joins the halves of a literal path, each as it reads and as it is written', function (): void {
    $path = new Concat(new Concat(new Dir(), new String_('/')), new Dir());

    expect(IncludePaths::in('/no/such/project')->literal($path, '/no/such/project/config/app.php'))
        ->toBe(['/no/such/project/config//no/such/project/config', '__DIR__/__DIR__']);
});
