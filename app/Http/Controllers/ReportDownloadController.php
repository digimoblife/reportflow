<?php

namespace App\Http\Controllers;

use App\Services\Report\SignedDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a report file through a signed URL (middleware `signed`). All decisions live in SignedDownload.
 */
class ReportDownloadController extends Controller
{
    public function show(Request $request, int $file, SignedDownload $downloads): StreamedResponse
    {
        $response = $downloads->response($file, (int) $request->query('u'));

        abort_if($response === null, 404);

        return $response;
    }
}
