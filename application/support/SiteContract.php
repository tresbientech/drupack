<?php

declare(strict_types=1);

namespace Drupack\Support;

// The fields of the Site contract this application reads, from the site.json the build
// writes. The build checks every field, so a missing or mistyped one fails here with the
// parameter's name.
final readonly class SiteContract
{
    public function __construct(
        public int $port,
        // The Site template's path in the project, or '' for a site built without one.
        public string $recipe,
        // A PHP file in the site that the generated settings.php requires last, or ''.
        public string $settings,
        public string $siteName,
        // The Site host label composer.json sets, or null when the executable's name is the label.
        public ?string $subdomain,
        // Whether the file carries Bundled Node.
        public bool $node,
        /** @var list<string> */
        public array $writable,
        public string $docroot,
        public bool $mercure,
    ) {
    }

    public static function read(string $path): self
    {
        $site = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return new self(
            port: $site['port'],
            recipe: $site['recipe'],
            settings: $site['settings'],
            siteName: $site['site_name'],
            // The build leaves out subdomain and node for a site that sets neither.
            subdomain: $site['subdomain'] ?? null,
            node: isset($site['node']),
            writable: $site['writable'],
            docroot: $site['docroot'],
            mercure: $site['mercure'],
        );
    }
}
