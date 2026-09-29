<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Port\TreeSource;

/** A tree source that answers the trees it was given. */
final readonly class TreeSourceFake implements TreeSource
{
    public function __construct(private Trees|CannotJudge $trees) {}

    /** The trees of the contract suite's fixture: one at each kind of declared floor. */
    public static function ofTheFixture(): self
    {
        $root = Package::at(Path::root());

        return new self(Trees::of(
            Tree::at(Path::of('src/Domain'), Floor::of(100), $root),
            Tree::at(Path::of('src/Http'), Undeclared::floor(), $root),
            Tree::at(Path::of('src/Generated'), Exempt::because('Generated on every build'), $root),
        ));
    }

    public function trees(): Trees|CannotJudge
    {
        return $this->trees;
    }
}
