<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextSourceStatus;
use App\Enums\OwnerContextSourceType;
use App\Jobs\ExtractOwnerContextSourceJob;
use App\Models\OwnerContextSource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class OwnerContextImportService
{
    public function storeUpload(User $user, UploadedFile $file, string $name, ?string $sourceDate): OwnerContextSource
    {
        $source = OwnerContextSource::query()->create([
            'user_id' => $user->id,
            'name' => trim($name) !== '' ? trim($name) : 'Owner context',
            'source_type' => OwnerContextSourceType::Upload,
            'source_date' => $sourceDate,
            'original_filename' => Str::limit((string) $file->getClientOriginalName(), 180, ''),
            'status' => OwnerContextSourceStatus::Uploaded,
        ]);

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $extension = in_array($extension, ['txt', 'md', 'markdown'], true) ? $extension : 'txt';
        $path = trim((string) config('owner_context.directory', 'owner-context'), '/').'/'.$user->id.'/'.$source->id.'.'.$extension;
        $bytes = (string) file_get_contents($file->getRealPath());
        Storage::disk((string) config('owner_context.disk', 'local'))->put($path, $bytes);

        $source->forceFill(['storage_path' => $path])->save();
        ExtractOwnerContextSourceJob::dispatch($source->id);

        return $source;
    }

    public function archive(OwnerContextSource $source): OwnerContextSource
    {
        $source->forceFill(['status' => OwnerContextSourceStatus::Archived])->save();

        return $source;
    }

    public function retry(OwnerContextSource $source): void
    {
        if (! in_array($source->status, [OwnerContextSourceStatus::Failed, OwnerContextSourceStatus::Partial], true)) {
            return;
        }

        $source->forceFill(['status' => OwnerContextSourceStatus::Uploaded])->save();
        ExtractOwnerContextSourceJob::dispatch($source->id);
    }
}
