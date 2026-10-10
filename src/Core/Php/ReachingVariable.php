<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/** A variable a scope does not own: the object a method runs on, and those PHP shares with every scope. */
enum ReachingVariable: string
{
    case This = '$this';

    case Globals = '$GLOBALS';

    case Server = '$_SERVER';

    case Get = '$_GET';

    case Post = '$_POST';

    case Files = '$_FILES';

    case Cookie = '$_COOKIE';

    case Session = '$_SESSION';

    case Request = '$_REQUEST';

    case Env = '$_ENV';
}
