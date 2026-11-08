<?php

namespace App\Shipments;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Many waybills in one download: a single PDF through pdfunite, or a ZIP when pdfunite is missing or fails.
 * The result is a temp file in storage/app/tmp that the caller deletes.
 */
final class LabelMerger
{
    /**
     * @param  array<string, string>  $paths  absolute PDF path by entry name (the ZIP names it "{name}.pdf"), in print order
     * @return array{type: 'pdf'|'zip', path: string}
     */
    public function merge(array $paths): array
    {
        if ($paths === []) {
            throw new RuntimeException('Nothing to merge.');
        }

        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new RuntimeException('The temp directory could not be made.');
        }
        $base = $dir.'/'.Str::uuid()->toString();

        if (($pdf = $this->unite(array_values($paths), $base.'.pdf')) !== null) {
            return ['type' => 'pdf', 'path' => $pdf];
        }

        return ['type' => 'zip', 'path' => $this->zip($paths, $base.'.zip')];
    }

    private function unite(array $paths, string $out): ?string
    {
        $binary = (string) config('couriers.pdfunite', '/usr/bin/pdfunite');
        if (! is_file($binary) || ! is_executable($binary)) {
            Log::warning('pdfunite is not available; falling back to a ZIP');

            return null;
        }

        try {
            $result = Process::timeout(60)->run([$binary, ...$paths, $out]);
            if ($result->successful() && is_file($out) && filesize($out) > 0) {
                return $out;
            }
            Log::warning('pdfunite failed; falling back to a ZIP', ['exit' => $result->exitCode()]);
        } catch (Throwable $e) {
            Log::warning('pdfunite could not run; falling back to a ZIP', ['exception' => $e::class]);
        }

        @unlink($out);

        return null;
    }

    private function zip(array $paths, string $out): string
    {
        $zip = new ZipArchive;
        if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP could not be made.');
        }

        try {
            foreach ($paths as $name => $path) {
                if (! $zip->addFile($path, $name.'.pdf')) {
                    throw new RuntimeException('A label could not be added to the ZIP.');
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('The ZIP could not be written.');
            }
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($out);

            throw $e;
        }

        return $out;
    }
}
