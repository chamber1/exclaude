<?php

namespace App\Http\Controllers;

use App\Downloads\DownloadOwner;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The /parser page. All work happens asynchronously through DownloadController (queue + status API).
 */
class ParserController extends Controller
{
    public function index(Request $request): View
    {
        $owner = DownloadOwner::fromRequest($request);

        return view('parser', [
            'formats' => config('downloads.formats'),
            'bitrates' => config('downloads.bitrates'),
            'qualities' => config('downloads.qualities'),
            'limits' => $owner->limits(),
            'guest' => $owner->isGuest(),
            'maxMinutes' => intdiv((int) config('downloads.max_duration'), 60),
            'fileTtlMinutes' => intdiv((int) config('downloads.file_ttl'), 60),
            'licensePolicy' => config('downloads.license_policy'),
        ]);
    }
}
