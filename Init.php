<?php

namespace zvs\logger;

use Composer\Script\Event;
use Composer\Installer\PackageEvent;

class Init
{
    public static function postUpdate(Event $event)
    {
        $composer = $event->getComposer();

        file_put_contents('test.txt', 'install');
    }

    public static function postAutoloadDump(Event $event)
    {
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        require $vendorDir . '/autoload.php';

        //some_function_from_an_autoloaded_file();
    }

    public static function postPackageInstall(PackageEvent $event)
    {
        $installedPackage = $event->getOperation()->getPackage();

        file_put_contents('test.txt', 'install');
    }

    public static function warmCache(Event $event)
    {
        // make cache toasty
    }
}