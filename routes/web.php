<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

Route::get('/', function () {
    return view('pages.home');
});

// CV download — handles the space in the filename safely
Route::get('/download-cv', function () {
    $path = public_path('files/LIBRADA_RESUME.pdf');
    return response()->download($path, 'LIBRADA_RESUME.pdf');
})->name('download.cv');

// ============================================================
// VIDEO STREAMING ROUTE — supports HTTP Range requests
// ============================================================
// php artisan serve does NOT support Range requests natively,
// which means the browser can't seek/scrub videos. This route
// handles Range headers manually so forward AND backward
// scrubbing works correctly on both mobile and desktop.
// ============================================================
Route::get('/video/{filename}', function (Request $request, string $filename) {

    // Security: only allow .mp4 files, block path traversal
    $filename = basename($filename);
    if (!preg_match('/\.mp4$/i', $filename)) {
        abort(404);
    }

    $path = public_path('screencast/' . $filename);
    if (!file_exists($path)) {
        abort(404);
    }

    $fileSize    = filesize($path);
    $mimeType    = 'video/mp4';
    $rangeHeader = $request->headers->get('Range');

    // No Range header — serve the whole file normally
    if (!$rangeHeader) {
        return response()->file($path, [
            'Content-Type'   => $mimeType,
            'Content-Length' => $fileSize,
            'Accept-Ranges'  => 'bytes',
        ]);
    }

    // Parse Range header e.g. "bytes=0-999999"
    preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m);
    $start = $m[1] !== '' ? (int)$m[1] : 0;
    $end   = $m[2] !== '' ? (int)$m[2] : $fileSize - 1;
    $end   = min($end, $fileSize - 1);

    if ($start > $end || $start >= $fileSize) {
        abort(416); // Range Not Satisfiable
    }

    $chunkSize = $end - $start + 1;

    // Stream only the requested byte range
    $response = new StreamedResponse(function () use ($path, $start, $chunkSize) {
        $fp        = fopen($path, 'rb');
        $remaining = $chunkSize;
        fseek($fp, $start);

        while ($remaining > 0 && !feof($fp)) {
            $read = min(256 * 1024, $remaining); // 256 KB per chunk
            echo fread($fp, $read);
            $remaining -= $read;
            flush();
        }

        fclose($fp);
    }, 206);

    $response->headers->set('Content-Type',   $mimeType);
    $response->headers->set('Content-Range',  "bytes {$start}-{$end}/{$fileSize}");
    $response->headers->set('Content-Length', (string)$chunkSize);
    $response->headers->set('Accept-Ranges',  'bytes');

    return $response;

})->where('filename', '[^/]+');