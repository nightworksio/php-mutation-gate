<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveFlush;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, a removed call, untagged, with its own hint', function (): void {
    $mutator = new RemoveFlush();

    expect($mutator->name()->value())->toBe('symfony/RemoveFlush')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test reads back what this flush saves.'));
});

it('removes the flush of an entity manager under both runners, emptying it under Infection', function (): void {
    $code = <<<'PHP'
        <?php

        final class Orders
        {
            public function save($em, $objectManager, $manager)
            {
                $em->flush();
                $this->entityManager->flush();
                $this->doctrine->getManager()->flush();
                $objectManager->flush();
                $manager->flush();
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveFlush(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        \$em->flush();",
        "-        \$this->entityManager->flush();",
        "-        \$this->doctrine->getManager()->flush();",
        "-        \$objectManager->flush();",
        "-        \$manager->flush();",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        \$em->flush();\n+        ",
            "-        \$this->entityManager->flush();\n+        ",
            "-        \$this->doctrine->getManager()->flush();\n+        ",
            "-        \$objectManager->flush();\n+        ",
            "-        \$manager->flush();\n+        ",
        ]);
});

it('leaves alone a flush of another object, another method and a flush whose result is used', function (): void {
    $code = <<<'PHP'
        <?php

        $stream->flush();
        $item->flush();
        $this->cache->flush();
        $em->persist($order);
        $flushed = $em->flush();
        PHP;

    expect(Mutates::with(new RemoveFlush(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveFlush()->mutate(new Nop()))->toEqual(Unchanged::node());
});
