<?php

declare(strict_types=1);

namespace App\Security;

enum LoginResult
{
    case Ok;
    case Invalid;
    case Throttled;
}
