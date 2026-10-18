<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Communication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files on a communication (module 03 slice S3, spec 11) — the polymorphic `attachments`
 * table that `00-data-model.md` deferred. Upload, download and remove all work through the
 * same door as the rest of the module: the record's rights decide who may, and the message's
 * state decides whether its files may still change.
 */
class AttachmentController extends Controller
{
    /**
     * Attach a file to a message. A message that has left (or arrived) keeps the files it
     * carried, so only a draft, a logged touch or a rejected send takes new ones — the request
     * raises that 422.
     */
    public function store(StoreAttachmentRequest $request, Communication $communication): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $disk = (string) config('crm.attachments.disk');

        /** @var Attachment $attachment */
        $attachment = $communication->attachments()->create([
            'disk' => $disk,
            // A generated name: the client's filename is kept for display only, never used as
            // a path (the uploader picks the name, not the storage layout).
            'path' => $file->store('attachments', $disk),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return (new AttachmentResource($attachment->load('uploader:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The stored file, streamed through an authorized endpoint — the disk and path are never
     * exposed to the client (spec 11 §S3).
     */
    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            abort(404, 'The file is missing from storage.');
        }

        return $disk->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Attachment $attachment): Response
    {
        $this->authorize('delete', $attachment);

        $record = $attachment->attachable;

        // The same state rule as uploading, from the other side (spec 11 §S3).
        if ($record instanceof Communication) {
            $reason = $record->attachmentLockReason();

            if ($reason !== null) {
                throw ValidationException::withMessages(['status' => [$reason]]);
            }
        }

        // The row goes; the model takes the stored file with it.
        $attachment->delete();

        return response()->noContent();
    }
}
