<?php

declare(strict_types=1);

namespace Drupack\Support;

// The fields of site.json that preparation reads. The build checks every field, so a missing
// or mistyped one fails here with the parameter's name.
final readonly class SiteContract
{
    public function __construct(
        // The Site template's path in the project, or '' for a site built without one.
        public string $recipe,
        // A PHP file in the site that the generated settings.php requires last, or ''.
        public string $settings,
        public string $siteName,
    ) {
    }

    public static function read(string $path): self
    {
        $site = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return new self(
            recipe: $site['recipe'],
            settings: $site['settings'],
            siteName: $site['site_name'],
        );
    }
}
