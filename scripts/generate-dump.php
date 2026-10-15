<?php
declare(strict_types=1);

/**
 * Regenerate the Linked Data dump from the command line.
 *
 * The same work as the "Generate the dump now" job, run synchronously, for
 * cron or for the end of the MongoDB2OmekaS sync:
 *
 *     php modules/DRELinkedData/scripts/generate-dump.php
 *     php generate-dump.php --omeka-path=/var/www/html --if-stale
 *
 * Options:
 *   --omeka-path=PATH  the Omeka S root (default: three levels up, i.e. the
 *                      Omeka install this module sits in)
 *   --if-stale         do nothing when the dump exists and no item, item set
 *                      or media changed since it was taken
 *   --quiet            print nothing on success
 *
 * Run it as the web server's user (www-data in the Docker image), so the
 * files it writes under files/dre-linked-data/ stay readable and replaceable
 * by the web process. The base URI must be configured (Modules → DRE Linked
 * Data → Configure): there is no request to take it from here.
 *
 * Exit codes: 0 written (or not stale), 1 failed, 2 bad invocation, 3 another
 * run holds the lock.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$options = getopt('', ['omeka-path:', 'if-stale', 'quiet', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php generate-dump.php [--omeka-path=PATH] [--if-stale] [--quiet]\n");
    exit(0);
}
$quiet = isset($options['quiet']);
$omekaPath = rtrim((string) ($options['omeka-path'] ?? dirname(__DIR__, 3)), '/\\');
if (!is_file($omekaPath . '/bootstrap.php') || !is_file($omekaPath . '/application/config/application.config.php')) {
    fwrite(STDERR, "ERROR: {$omekaPath} is not an Omeka S installation; pass --omeka-path.\n");
    exit(2);
}

// Omeka's bootstrap first: it defines OMEKA_PATH, chdir()s to it, loads the
// vendor autoloader and the PHP 8.5 class overrides. The module's own classes
// are then autoloaded by Omeka's module manager.
require $omekaPath . '/bootstrap.php';

use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpGenerator;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Settings\DatasetSettings;

try {
    $application = Omeka\Mvc\Application::init(require OMEKA_PATH . '/application/config/application.config.php');
    $services = $application->getServiceManager();

    $module = $services->get('Omeka\ModuleManager')->getModule('DRELinkedData');
    if ($module === null || $module->getState() !== Omeka\Module\Manager::STATE_ACTIVE) {
        fwrite(STDERR, "ERROR: the DRE Linked Data module is not installed and active.\n");
        exit(2);
    }
    if (trim((string) $services->get('Omeka\Settings')->get(DatasetSettings::BASE_URI, '')) === '') {
        fwrite(STDERR, "ERROR: no base URI is configured (Modules > DRE Linked Data > Configure).\n");
        exit(2);
    }

    if (isset($options['if-stale'])) {
        $state = $services->get(DumpStore::class)->state();
        if ($state !== null && $services->get(ChangeTracker::class)->pending($state) === null) {
            if (!$quiet) {
                fwrite(STDOUT, "The dump is up to date; nothing to do.\n");
            }
            exit(0);
        }
    }

    $state = $services->get(DumpGenerator::class)->generate();
    if ($state === null) {
        fwrite(STDERR, "Another dump run holds the lock; nothing was written.\n");
        exit(3);
    }
    if (!$quiet) {
        fwrite(STDOUT, sprintf(
            "Wrote %d triples for %d resources (%d bytes, %.1f s) at %s.\n",
            $state['triples'],
            array_sum($state['resources']),
            $state['bytes'],
            $state['duration_seconds'],
            $state['generated_at']
        ));
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}
