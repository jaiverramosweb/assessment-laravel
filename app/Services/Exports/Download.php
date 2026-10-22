<?php

namespace App\Services\Exports;

use Illuminate\Http\Response;

/** Download responses of the exports (port of the Response in src/lib/server/export/*-download.ts). */
final class Download
{
    public const TYPES = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public const LABELS = ['xlsx' => 'Excel', 'pdf' => 'PDF'];

    public static function response(string $body, string $format, string $fileName): Response
    {
        return response($body, 200, [
            'Content-Type' => self::TYPES[$format],
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}
