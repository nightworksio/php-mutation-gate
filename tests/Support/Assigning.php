<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_values;

use NightWorksIO\MutationGate\Core\Php\OwnVariables;
use PhpToken;

use function sprintf;

/** Statements written as code, read as a scope's statements are. */
final readonly class Assigning
{
    /** The variables of a scope's own, as these statements assign them. */
    public static function own(string ...$statements): OwnVariables
    {
        $read = [];

        foreach ($statements as $statement) {
            $tokens = array_values(array_filter(
                PhpToken::tokenize(sprintf('<?php %s', $statement)),
                static fn(PhpToken $token): bool => ! $token->isIgnorable() && ! $token->is(T_OPEN_TAG),
            ));

            if ($tokens !== []) {
                $read[] = $tokens;
            }
        }

        return OwnVariables::assignedIn($read);
    }
}
