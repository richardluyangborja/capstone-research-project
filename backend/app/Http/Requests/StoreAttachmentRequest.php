<?php

namespace App\Http\Requests;

use App\Models\Attachment;
use App\Models\Communication;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Attach a file to a message (spec 11 §S3). Two rules sit on top of the upload itself: the
 * caller must be able to work the record (a 403 otherwise), and the message must still take
 * files — a delivered or received one keeps what it carried (a 422).
 */
class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $communication = $this->route('communication');

        return $communication instanceof Communication
            && ($this->user()?->can('create', [Attachment::class, $communication]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The same allow-list and ceiling as a contract document (spec 11 §S3).
            'file' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg',
                'max:'.(int) config('crm.attachments.max_kb'),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $communication = $this->route('communication');

                if (! $communication instanceof Communication) {
                    return;
                }

                $reason = $communication->attachmentLockReason();

                if ($reason !== null) {
                    $validator->errors()->add('status', $reason);

                    return;
                }

                $max = (int) config('crm.attachments.max_files');
                $carried = $communication->attachments()->count();

                if ($carried >= $max) {
                    $validator->errors()->add(
                        'file',
                        sprintf('A communication can carry at most %d files.', $max),
                    );
                }
            },
        ];
    }
}
