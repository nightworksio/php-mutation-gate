<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\DiscordMessage;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('sends one embed linked to the run, its groups in the description, and lets it mention no one', function (): void {
    $message = DiscordMessage::json(Alert::of(AlertEvent::Failed, Verdicts::failing(), TrendEntry::none()), Previous::ci());

    expect(Decoded::at($message, 'allowed_mentions'))->toBe(['parse' => []])
        ->and(Decoded::at($message, 'embeds'))->toHaveCount(1)
        ->and(Decoded::at($message, 'embeds', 0))->toMatchArray([
            'title' => 'mutation-gate: failed on octo/gate main',
            'url' => 'https://github.example/octo/gate/actions/runs/7',
            'color' => 0xCB_24_31,
        ])
        ->and(Decoded::at($message, 'embeds', 0, 'description'))->toStartWith(
            "**Below the floor**\n`src`: 44.44%, below its floor of 80.00%.\n\n**Failures**\n",
        );
});

it('is red for a failure, green for a recovery, and grey otherwise', function (AlertEvent $event, int $color): void {
    $message = DiscordMessage::json(Alert::of($event, Verdicts::passing(), TrendEntry::none()), Previous::ci());

    expect(Decoded::at($message, 'embeds', 0, 'color'))->toBe($color);
})->with([
    'failed' => [AlertEvent::Failed, 0xCB_24_31],
    'recovered' => [AlertEvent::Recovered, 0x28_A7_45],
    'cannot judge' => [AlertEvent::CannotJudge, 0x6A_73_7D],
    'floor lowered' => [AlertEvent::FloorLowered, 0x6A_73_7D],
]);

it('leaves out the link and the description where there are none', function (): void {
    $message = DiscordMessage::json(
        Alert::of(AlertEvent::FloorLowered, Verdicts::passing(), TrendEntry::none()),
        CiRun::of('octo/gate', 'refs/heads/main', 'c', ''),
    );

    expect(Decoded::at($message, 'embeds', 0))->not->toHaveKeys(['url', 'description']);
});

it('keeps its title and description within what Discord takes, however long the names', function (): void {
    $long = str_repeat('a', 400);
    $run = CiRun::of(sprintf('octo/%s', $long), 'refs/heads/main', 'c', 'https://ci.example/7');
    $message = DiscordMessage::json(Alert::of(AlertEvent::Failed, Verdicts::crowded(), TrendEntry::none()), $run);
    $title = Decoded::at($message, 'embeds', 0, 'title');
    $description = Decoded::at($message, 'embeds', 0, 'description');

    expect(is_string($title) ? mb_strlen($title) : 0)->toBe(256)
        ->and(is_string($description) ? mb_strlen($description) : 0)->toBeGreaterThan(1_800)->toBeLessThanOrEqual(2_000)
        ->and($description)->toEndWith(' more.');
});

it('keeps a description of exactly Discord\'s two thousand characters whole, and cuts one a character past it', function (): void {
    $text = static function (int $last): string {
        $trees = [];

        foreach (range(1, 11) as $each) {
            $path = $each === 11 ? str_repeat('b', $last) : sprintf('%s%d', str_repeat('a', 100), $each);
            $trees[] = TreeVerdict::judged(
                Tree::at(Path::of($path), Floor::of(90), Package::at(Path::root())),
                Unrecorded::floor(),
                JudgedUnits::none(),
                JudgedMutants::of(JudgedMutant::of(
                    Verdicts::mutant(sprintf('%s/A.php:1', $path), 'Plus', MutatorFamily::Arithmetic, ''),
                    MutantJudgement::Survived,
                )),
                Uncovered::Count,
            );
        }

        $said = Decoded::at(DiscordMessage::json(Alert::of(AlertEvent::Failed, Verdict::of(TreeVerdicts::of(...$trees)), TrendEntry::none()), Previous::ci()), 'embeds', 0, 'description');

        return is_string($said) ? $said : '';
    };
    $fits = 2_000 - (mb_strlen($text(1)) - 1);

    expect(mb_strlen($text($fits)))->toBe(2_000)
        ->and(mb_strlen($text($fits + 1)))->toBeLessThan(2_000)
        ->and($text($fits + 1))->toMatch('/And \\d+ more\\.$/');
});
