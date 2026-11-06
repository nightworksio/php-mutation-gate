<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\Enumerated;
use NightWorksIO\MutationGate\Core\Config\Definition\Items;
use NightWorksIO\MutationGate\Core\Config\Definition\VerdictFirst;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Format\Node;

it('reads a list that starts with the verdict, and refuses one that does not, or that names no section', function (): void {
    $read = VerdictFirst::sections()->read(Node::config('["verdict", "trees"]'))->value();

    expect($read instanceof Listed ? [...$read] : [])->toBe([Section::Verdict, Section::Trees])
        ->and(VerdictFirst::sections()->read(Node::config('["trees", "verdict"]'))->problems())->not->toBe([])
        ->and(VerdictFirst::sections()->read(Node::config('["nothing"]'))->problems())->not->toBe([]);
});

it('says what it expects, and describes the list its sections do', function (): void {
    $sections = Items::distinctAtLeastOne(Enumerated::of(Section::cases()), static fn(Section $section): string => $section->value);

    expect(VerdictFirst::sections()->expected())->toBe('a list of sections that starts with verdict')
        ->and(VerdictFirst::sections()->schema())->toEqual($sections->schema())
        ->and(VerdictFirst::sections()->effects())->toEqual($sections->effects());
});
