<?php

declare(strict_types=1);

namespace Drupack\Support;

// What an owed initialization step needs before a first start changes anything.
enum Need
{
    // The Site template, which the build turns into the Seed site.
    case Recipe;
    // The database server's connection details, from the command line.
    case Connection;
    // The administrator's name and password, generated when the reader gives none.
    case Credentials;
}
