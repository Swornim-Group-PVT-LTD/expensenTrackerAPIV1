<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class MoveExpenseAttachments extends Command
{
    protected $signature = 'expenses:move-attachments {source? : Directory containing upload_receipt and upload_doc}';

    protected $description = 'Move legacy expense attachments to private storage';

    public function handle(): int
    {
        $source = $this->argument('source') ?? public_path();
        if (!is_dir($source)) {
            $this->error("Attachment source directory does not exist: {$source}");

            return self::FAILURE;
        }

        $disk = Storage::disk('expense_attachments');
        $moved = 0;

        foreach (['upload_receipt', 'upload_doc'] as $directory) {
            $from = $source.DIRECTORY_SEPARATOR.$directory;

            if (is_link($from)) {
                $this->error("Attachment source directory is a symlink: {$from}");

                return self::FAILURE;
            }

            if (!is_dir($from)) {
                continue;
            }

            $disk->makeDirectory($directory);

            foreach (File::files($from) as $file) {
                if ($file->isLink()) {
                    $this->error("Remove the symlink from the attachment source: {$file->getPathname()}");

                    return self::FAILURE;
                }

                $target = $disk->path($directory.'/'.$file->getFilename());

                if (file_exists($target)) {
                    $sourceHash = hash_file('sha256', $file->getPathname());
                    if ($sourceHash === false || $sourceHash !== hash_file('sha256', $target)
                        || !File::delete($file->getPathname())) {
                        $this->error("Unable to reconcile existing attachment: {$file->getFilename()}");

                        return self::FAILURE;
                    }
                } elseif (!File::move($file->getPathname(), $target)) {
                    $this->error("Unable to move attachment: {$file->getFilename()}");

                    return self::FAILURE;
                }

                $moved++;
            }
        }

        $this->info("Moved {$moved} expense attachments.");

        return self::SUCCESS;
    }
}
