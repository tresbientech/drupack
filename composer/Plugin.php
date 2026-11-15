<?php

declare(strict_types=1);

namespace Drupal\Drupack\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\PhpExecutableFinder;

// Runs vendor/bin/drupack-install after each install and update. The bin runs in its own
// PHP process, which loads the project's autoloader in place of Composer's.
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'install',
            ScriptEvents::POST_UPDATE_CMD => 'install',
        ];
    }

    public function install(Event $event): void
    {
        $bin = $event->getComposer()->getConfig()->get('bin-dir') . '/drupack-install';
        // The finder reads the PHP_BINARY variable first. Under drupack composer, PHP's own
        // constant names the drupack executable, which takes no PHP script.
        $php = (new PhpExecutableFinder())->find(false);
        $status = (new ProcessExecutor($event->getIO()))->execute([$php, $bin]);
        if ($status !== 0) {
            throw new \RuntimeException("$bin exited with status $status.");
        }
    }
}
