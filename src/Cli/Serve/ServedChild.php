<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Serve;

use function chdir;

use NightWorksIO\MutationGate\Adapter\Serve\ChildEnvironment;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * A daemon's child made ready to run a request as the client's process would
 * have run it (ADR-0078): the request's environment in place of the
 * daemon's, the project's root as its directory, and the request's command
 * line reading what the client read on standard input.
 *
 * @internal
 */
final readonly class ServedChild
{
    /** The request's command line, once this process is made ready to run it in this root. */
    public static function prepared(ServeRequest $request, string $root): ArgvInput
    {
        ChildEnvironment::madeAs($request->environment());
        chdir($root);
        $input = new ArgvInput($request->argv());
        Serving::reading($input, $request->input());

        return $input;
    }
}
