<?php

declare(strict_types=1);

namespace Drupack\Support;

// What an owed initialization step needs before a first start changes anything.
enum Need: string
{
    // The Site template, which the build turns into the seed.
    case Recipe = 'recipe';
    // The database server's connection details, from the command line.
    case Connection = 'connection';
    // The administrator's name and password, generated when the reader gives none.
    case Credentials = 'credentials';
}
