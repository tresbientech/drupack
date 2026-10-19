<?php

declare(strict_types=1);

namespace Drupack\Support;

// One initialization step a first start owes. The values are the names the progress file
// records, so a start resumes an initialization an earlier start of this release began.
enum Step: string
{
    case Seed = 'seed';
    case Settings = 'settings';
    case Administrator = 'administrator';
    case Install = 'install';
    case Modules = 'modules';
    case Adopt = 'adopt';

    // What the step tells the person waiting. A first start spends minutes inside one
    // step, and prints nothing else until the site answers.
    public function report(): string
    {
        return match ($this) {
            self::Seed => 'Copying the packaged site into Site data',
            self::Settings => 'Writing the site settings',
            self::Install => 'Installing the site',
            self::Modules => 'Removing modules the application cannot run',
            self::Administrator => 'Creating the administrator account',
            self::Adopt => 'Adopting the site already in Site data',
        };
    }

    /** @return list<Need> */
    public function needs(): array
    {
        return match ($this) {
            self::Seed => [Need::Recipe],
            self::Settings => [Need::Connection],
            self::Administrator, self::Install => [Need::Credentials],
            self::Modules, self::Adopt => [],
        };
    }

    /**
     * What the owed $steps need together, in the order Need declares.
     *
     * @param list<Step> $steps
     * @return list<Need>
     */
    public static function owed(array $steps): array
    {
        $needs = array_merge(...array_map(fn(self $step) => $step->needs(), $steps));
        return array_values(array_filter(Need::cases(), fn(Need $need) => in_array($need, $needs, true)));
    }

    /**
     * The steps and their needs on one line, as "seed settings administrator | recipe connection credentials".
     *
     * @param list<Step> $steps
     */
    public static function describe(array $steps): string
    {
        $names = fn(array $cases) => implode(' ', array_map(fn(\BackedEnum $case) => $case->value, $cases));
        return $names($steps) . ' | ' . $names(self::owed($steps));
    }
}
