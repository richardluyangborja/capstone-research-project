<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file hanging off a record (module 03 slice S3, spec 11) — the polymorphic table
 * `00-data-model.md` reserved for records that need several files. Communications are its
 * first user; the single contract document can move onto it later.
 *
 * The stored path is never exposed through the API: a caller sees the file's identity, and
 * the bytes are streamed from `GET /attachments/{id}/file`, never from a public URL.
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The file exists for this row and for nothing else, so removing the row removes the
        // file too — there is no separate "retired but kept" state for a file (spec 11 §S3).
        static::deleting(function (Attachment $attachment): void {
            $attachment->removeFile();
        });
    }

    /**
     * The record the file belongs to (a communication today).
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Delete the stored file. Kept public because a caller removing a file out-of-band (the
     * seeder replacing a placeholder) should use the same door.
     */
    public function removeFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }
}
