<?php

declare(strict_types=1);

// Needs the vendor/ autoloader, for Symfony's kernel events and core's PublicStream.

require __DIR__ . '/cases.php';
registerTestSupport();

use Drupack\Support\ExportedUploadSubscriber;
use Drupack\Support\SiteDataPublicStream;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$root = sys_get_temp_dir() . '/drupack-exported-upload-' . bin2hex(random_bytes(6));
mkdir("$root/files/2026-04", 0700, true);
mkdir("$root/outside", 0700, true);
file_put_contents("$root/files/2026-04/a photo.jpg", 'photo');
file_put_contents("$root/outside/secret.txt", 'secret');
putenv("DRUPACK_RUNTIME_FILES_DIR=$root/files");
stream_wrapper_register('public', SiteDataPublicStream::class);

// The response the subscriber sets for $path, or null. $export marks the request as Tome's.
function answer(string $path, bool $export = true): ?BinaryFileResponse
{
    $request = Request::create($path);
    if ($export) {
        $request->attributes->set('tome', 'tome');
    }
    $kernel = new class implements HttpKernelInterface {
        public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
        {
            throw new LogicException('unused');
        }
    };
    $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    (new ExportedUploadSubscriber('tome'))->onRequest($event);
    return $event->getResponse();
}

test('an export request for a stored file answers that file', function () {
    same('public://2026-04/a photo.jpg', answer('/sites/default/files/2026-04/a%20photo.jpg?itok=x')?->getFile()->getPathname());
});

test('a request from the network gets no answer', function () {
    same(null, answer('/sites/default/files/2026-04/a%20photo.jpg', false));
});

test('an absent file, a directory and a path outside the files address get no answer', function () {
    foreach (['/sites/default/files/2026-04/absent.jpg', '/sites/default/files/2026-04', '/core/misc/favicon.ico'] as $path) {
        same(null, answer($path), $path);
    }
});

test('a path that walks out of public storage gets no answer', function () {
    same(null, answer('/sites/default/files/..%2Foutside/secret.txt'));
    same(null, answer('/sites/default/files/%2e%2e/outside/secret.txt'));
});

$status = runCases();

stream_wrapper_unregister('public');
putenv('DRUPACK_RUNTIME_FILES_DIR');
unlink("$root/files/2026-04/a photo.jpg");
unlink("$root/outside/secret.txt");
rmdir("$root/files/2026-04");
rmdir("$root/files");
rmdir("$root/outside");
rmdir($root);

exit($status);
