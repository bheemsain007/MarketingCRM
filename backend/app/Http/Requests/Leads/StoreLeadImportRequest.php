<?php

namespace App\Http\Requests\Leads;

use App\Enums\Permission;
use App\Models\LeadImport;
use App\Support\LeadColumnMap;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LeadImport::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                /*
                 * Extension allowlist and size cap, but deliberately NO mime
                 * check - a considered deviation from SEC-FILE-01.
                 *
                 * Exported "CSV" files arrive as text/csv, text/plain,
                 * application/vnd.ms-excel or application/octet-stream
                 * depending on the browser and OS, so a mime allowlist either
                 * rejects valid uploads or is permissive enough to prove
                 * nothing. The control's real purpose - stopping uploaded
                 * content from being served or executed - is met structurally
                 * instead: the file is stored under a generated name on a
                 * private disk outside the web root (SEC-FILE-02) and is only
                 * ever read with fgetcsv.
                 */
                'extensions:csv,txt,tsv',
                'max:'.(int) config('crm.imports.max_file_kb'),
            ],

            // Explicit header mapping, for files whose columns auto-detection
            // cannot recognise. field => header label.
            'column_map' => ['nullable', 'array'],
            'column_map.*' => ['string', 'max:190'],

            // Applied to every lead the file produces.
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],

            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],

            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],

            'auto_assign' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_keys((array) $this->input('column_map', [])) as $field) {
                if (! in_array($field, LeadColumnMap::FIELDS, true)) {
                    $validator->errors()->add(
                        'column_map.'.$field,
                        sprintf('"%s" is not an importable lead field.', $field),
                    );
                }
            }

            /*
             * Auto-assignment during import distributes work across the team,
             * which is a supervisory act (BR-ASSIGN-01). Without this check a
             * telecaller with leads.import could hand themselves - or a
             * colleague - several thousand leads in one upload.
             */
            if ($this->boolean('auto_assign') && ! $this->user()->hasPermission(Permission::LeadsAssign)) {
                $validator->errors()->add(
                    'auto_assign',
                    'You do not have permission to assign leads. Import them unassigned instead.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.extensions' => 'Upload a CSV file. In Excel choose File → Save As → CSV (Comma delimited).',
            'file.max' => 'The file is larger than the :max KB limit. Split it and upload in parts.',
        ];
    }

    /**
     * Options the service acts on, separated from the file itself.
     *
     * @return array<string, mixed>
     */
    public function importOptions(): array
    {
        return [
            'column_map' => (array) $this->input('column_map', []),
            'lead_source_id' => $this->input('lead_source_id'),
            'campaign_id' => $this->input('campaign_id'),
            'tag_ids' => (array) $this->input('tag_ids', []),
            'product_ids' => (array) $this->input('product_ids', []),
            'auto_assign' => $this->boolean('auto_assign'),
        ];
    }
}
