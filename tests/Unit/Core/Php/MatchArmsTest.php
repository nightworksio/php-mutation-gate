<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\MatchArms;
use NightWorksIO\MutationGate\Core\Php\Tokens;

const MATCH_ARMS_CODE = <<<'PHP'
    <?php
    final class Band
    {
        public function of(int $n, bool $loud): string
        {
            return strtoupper(match (true) {
                $n > 10 => 'high',
                default => $loud ? match ($n) {
                    0 => 'none',
                    default => 'low',
                } : 'quiet',
            });
        }
        public function flat(int $n): int
        {
            return match ($n) { 1 => 1, default => 0 };
        }
    }
    PHP;

/**
 * The first and last line of the arms of the match whose head holds the nth token written as this text, or none.
 *
 * @return list<int>
 */
function matchArmsAt(string $code, string $text, int $nth = 0): array
{
    $tokens = Tokens::in(Contents::of($code));
    $found = array_values(array_filter(range(0, $tokens->count() - 1), static fn(int $at): bool => $tokens->text($at) === $text));
    $arms = MatchArms::of($tokens, $found[$nth]);

    return $arms instanceof MatchArms ? [$arms->first()->number(), $arms->last()->number()] : [];
}

it('spans the lines of the arms of a match whose head holds the token, wherever the match stands', function (): void {
    expect(matchArmsAt(MATCH_ARMS_CODE, 'true'))->toBe([7, 12])
        ->and(matchArmsAt(MATCH_ARMS_CODE, 'match', 0))->toBe([7, 12])
        ->and(matchArmsAt(MATCH_ARMS_CODE, '{', 2))->toBe([7, 12])
        ->and(matchArmsAt(MATCH_ARMS_CODE, '$n', 2))->toBe([9, 11])
        ->and(matchArmsAt("<?php\n\$b = match (true) {\n    default => 1 };", 'true'))->toBe([3, 3]);
});

it('has no arms for a token outside every match\'s head, or a match whose arms close on its head\'s line', function (): void {
    expect(matchArmsAt(MATCH_ARMS_CODE, "'high'"))->toBe([])
        ->and(matchArmsAt(MATCH_ARMS_CODE, 'strtoupper'))->toBe([])
        ->and(matchArmsAt(MATCH_ARMS_CODE, '$n', 4))->toBe([])
        ->and(matchArmsAt("<?php\n\$a = match (true) {\n    default => 1,\n", 'true'))->toBe([])
        ->and(MatchArms::of(Tokens::in(Contents::of('<?php $a = match;')), 3))->toEqual(NotGiven::value());
});
