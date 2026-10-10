<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\IncludeKind;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Scalar\String_;

it('names each kind of include by the word PHP spells it with, and its article', function (int $type, IncludeKind $kind, string $said): void {
    expect(IncludeKind::of(new Include_(new String_('a.php'), $type)))->toBe($kind)
        ->and($kind->said())->toBe($said);
})->with([
    'include' => [Include_::TYPE_INCLUDE, fn(): IncludeKind => IncludeKind::Include, 'an `include`'],
    'include_once' => [Include_::TYPE_INCLUDE_ONCE, IncludeKind::IncludeOnce, 'an `include_once`'],
    'require' => [Include_::TYPE_REQUIRE, fn(): IncludeKind => IncludeKind::Require, 'a `require`'],
    'require_once' => [Include_::TYPE_REQUIRE_ONCE, IncludeKind::RequireOnce, 'a `require_once`'],
]);
