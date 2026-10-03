<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Editor\VsCode;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Report\ResultRule;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/**
 * Each line of the problems output as the task's matcher reads it: every named group, by its name.
 *
 * @return list<array<string, string>>
 */
function matchedProblems(string $text): array
{
    $pattern = static fn(string $key): mixed => Decoded::at(VsCode::tasks(), 'tasks', 0, 'problemMatcher', 'pattern', $key);
    $regexp = $pattern('regexp');
    $read = [];

    foreach (explode("\n", rtrim($text, "\n")) as $line) {
        preg_match(sprintf('/%s/', is_string($regexp) ? $regexp : ''), $line, $groups);
        $named = [];

        foreach (['file', 'line', 'column', 'severity', 'message', 'code'] as $name) {
            $group = $pattern($name);
            $named[$name] = is_int($group) && array_key_exists($group, $groups) ? $groups[$group] : '';
        }

        $read[] = $named;
    }

    return $read;
}

it('writes a background task that watches with the problems output, and the matcher that reads it', function (): void {
    $tasks = VsCode::tasks();

    expect(Decoded::at($tasks, 'version'))->toBe('2.0.0')
        ->and(Decoded::at($tasks, 'tasks'))->toHaveCount(1)
        ->and(Decoded::at($tasks, 'tasks', 0))->toMatchArray([
            'label' => 'mutation-gate: watch',
            'type' => 'shell',
            'command' => 'vendor/bin/mutation-gate watch --output=problems',
            'isBackground' => true,
        ])
        ->and(Decoded::at($tasks, 'tasks', 0, 'problemMatcher'))->toMatchArray([
            'owner' => 'mutation-gate',
            'source' => 'mutation-gate',
            'fileLocation' => ['relative', '${workspaceFolder}'],
        ])
        ->and(Decoded::at($tasks, 'tasks', 0, 'problemMatcher', 'background'))->toBe([
            'activeBegins' => false,
            'beginsPattern' => '^mutation-gate: judging$',
            'endsPattern' => '^mutation-gate: judged$',
        ])
        ->and(Decoded::at(VsCode::task(), 'label'))->toBe(VsCode::LABEL)
        ->and(json_decode(VsCode::task(), associative: true))->toBe(Decoded::at($tasks, 'tasks', 0));
});

it('reads every line the problems output writes into its file, place, severity, message and rule', function (): void {
    $text = Problems::text(Verdicts::failing(), Sources::none()->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY)), ProblemsShown::All);
    $read = matchedProblems($text);

    expect($read)->toHaveCount(5)
        ->and($read[0])->toMatchArray(['file' => 'src/Money.php', 'line' => '7', 'column' => '21', 'severity' => 'error', 'code' => 'survived'])
        ->and($read[0]['message'])->toStartWith('Mutant survived: LessToLessOrEqual. ')
        ->and($read[2])->toMatchArray(['file' => 'src/Order.php', 'code' => 'flaky'])
        ->and($read[2]['message'])->toEndWith(' (proved)')
        ->and(array_column($read, 'code'))->each->toBeIn(array_map(static fn(ResultRule $rule): string => $rule->value, ResultRule::cases()));
});

it('reads a message that holds brackets, colons and what looks like a rule and an id, up to its own rule', function (): void {
    $survivor = Verdicts::survivor()->hinted(Hint::that('See src/A.php:3:4: error: x [flaky] 0123456789ab and [unjudged].'));
    [$read] = matchedProblems(Problems::text(Verdicts::of(Floor::of(0), $survivor), Sources::none(), ProblemsShown::All));

    expect($read)->toMatchArray(['file' => 'src/Money.php', 'line' => '7', 'column' => '1', 'severity' => 'warning', 'code' => 'survived'])
        ->and($read['message'])->toContain('See src/A.php:3:4: error: x [flaky] 0123456789ab and [unjudged]. Reproduce: ');
});

it('matches the lines that begin and end a judgement, and nothing else with them', function (): void {
    $begins = Decoded::at(VsCode::tasks(), 'tasks', 0, 'problemMatcher', 'background', 'beginsPattern');
    $ends = Decoded::at(VsCode::tasks(), 'tasks', 0, 'problemMatcher', 'background', 'endsPattern');

    expect(preg_match(sprintf('/%s/', is_string($begins) ? $begins : ''), Problems::JUDGING))->toBe(1)
        ->and(preg_match(sprintf('/%s/', is_string($ends) ? $ends : ''), Problems::JUDGED))->toBe(1)
        ->and(preg_match(sprintf('/%s/', is_string($begins) ? $begins : ''), Problems::JUDGED))->toBe(0);
});

it('recommends the SARIF Viewer', function (): void {
    expect(Decoded::at(VsCode::extensions(), 'recommendations'))->toBe(['MS-SarifVSCode.sarif-viewer']);
});
