<?php

namespace App\Http\Requests\Calls;

use Illuminate\Foundation\Http\FormRequest;

class StoreCallRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // The route permission and the CallPolicy are the gates.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                /*
                 * Extension allowlist and size cap, matching the reasoning the
                 * CSV import documents (SEC-FILE-01): handsets label the same
                 * AMR or M4A as audio/*, application/octet-stream or nothing at
                 * all depending on the OS, so a mime allowlist would reject
                 * valid uploads while proving little.
                 *
                 * The control that actually matters is structural: the file is
                 * stored under a generated name on a PRIVATE disk with no URL,
                 * outside the web root, and is only ever streamed back through
                 * an authorised route (SEC-FILE-02/03) - so it is never served
                 * as itself and never executed.
                 */
                'extensions:mp3,m4a,aac,amr,ogg,opus,wav,3gp',
                'max:'.(int) config('crm.recordings.max_file_kb'),
            ],

            // The device knows the real length; the call's own duration is only
            // a fallback (FR-REC-03).
            'duration_seconds' => ['nullable', 'integer', 'min:0'],

            // When the handset captured it, which is not when it arrived: an
            // offline queue may upload days later (FR-REC-02).
            'device_captured_at' => ['nullable', 'date'],
        ];
    }
}
