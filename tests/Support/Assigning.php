<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

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
            $tokens = [];

            foreach (PhpToken::tokenize(sprintf('<?php %s', $statement)) as $token) {
                if (! $token->isIgnorable() && ! $token->is(T_OPEN_TAG)) {
                    $tokens[] = $token;
                }
            }

            if ($tokens !== []) {
                $read[] = $tokens;
            }
        }

        return OwnVariables::assignedIn($read);
    }
}
