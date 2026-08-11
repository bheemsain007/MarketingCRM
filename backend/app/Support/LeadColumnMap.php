<?php

namespace App\Support;

/**
 * Maps spreadsheet headers onto lead fields (FR-LEAD-07).
 *
 * Real files come from wherever the client got them - an old CRM export, a
 * trade-show list, a marketplace download - and the columns are never called
 * what our schema calls them. Auto-detection covers the common labels so the
 * usual upload needs no configuration; an explicit `column_map` always wins,
 * for the file that calls its phone column "Contact 1".
 *
 * Only these fields are importable. Status, temperature, score, assignment and
 * suppression are deliberately NOT importable: they are business state owned by
 * their own services and rules (SEC-IN-06, BR-STAT-02). A file that could set
 * `status = converted` would bypass the entire status matrix.
 */
class LeadColumnMap
{
    /** Lead fields an import may populate. */
    public const FIELDS = [
        'name', 'phone', 'alt_phone', 'email', 'company',
        'city', 'state', 'country', 'timezone', 'priority', 'note',
    ];

    /** Without these two a row cannot become a lead. */
    public const REQUIRED = ['name', 'phone'];

    /**
     * Header labels recognised per field, already normalised.
     *
     * @return array<string, array<int, string>>
     */
    public static function aliases(): array
    {
        return [
            'name' => ['name', 'fullname', 'leadname', 'customername', 'contactname', 'clientname', 'firstname', 'person'],
            'phone' => ['phone', 'phonenumber', 'phoneno', 'mobile', 'mobilenumber', 'mobileno', 'contact', 'contactnumber', 'contactno', 'cell', 'whatsapp', 'whatsappnumber'],
            'alt_phone' => ['altphone', 'alternatephone', 'alternatenumber', 'alternatemobile', 'secondaryphone', 'secondarynumber', 'phone2', 'mobile2', 'contact2'],
            'email' => ['email', 'emailaddress', 'mail', 'emailid'],
            'company' => ['company', 'companyname', 'organisation', 'organization', 'firm', 'business', 'businessname'],
            'city' => ['city', 'town', 'location'],
            'state' => ['state', 'region', 'province'],
            'country' => ['country'],
            'timezone' => ['timezone'],
            'priority' => ['priority'],
            'note' => ['note', 'notes', 'remark', 'remarks', 'comment', 'comments', 'message', 'requirement'],
        ];
    }

    /**
     * Resolves header labels to lead fields.
     *
     * @param  array<int, string>  $header  labels as they appear in the file
     * @param  array<string, string>  $overrides  field => header label, from the client
     * @return array<string, string> field => header label
     */
    public static function resolve(array $header, array $overrides = []): array
    {
        $map = [];
        $taken = [];

        // Auto-detection first, so an override can correct it rather than
        // having to restate the whole map.
        foreach (self::aliases() as $field => $aliases) {
            foreach ($header as $label) {
                if (in_array($label, $taken, true)) {
                    continue;
                }

                if (in_array(self::normalise($label), $aliases, true)) {
                    $map[$field] = $label;
                    $taken[] = $label;

                    break;
                }
            }
        }

        foreach ($overrides as $field => $label) {
            if (! in_array($field, self::FIELDS, true)) {
                continue;
            }

            // An override naming a column the file does not have is ignored
            // rather than silently mapping to nothing; the missing-required
            // check below then reports it in terms the operator understands.
            if (in_array($label, $header, true)) {
                $map[$field] = $label;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     * @return array<int, string> required fields the map does not cover
     */
    public static function missingRequired(array $map): array
    {
        return array_values(array_filter(
            self::REQUIRED,
            fn (string $field) => ! isset($map[$field]) || $map[$field] === '',
        ));
    }

    /**
     * Pulls the mapped values out of one raw row.
     *
     * @param  array<string, string>  $row  header label => value
     * @param  array<string, string>  $map  field => header label
     * @return array<string, string> field => value, empty values dropped
     */
    public static function apply(array $row, array $map): array
    {
        $values = [];

        foreach ($map as $field => $label) {
            $value = trim((string) ($row[$label] ?? ''));

            if ($value !== '') {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /** Lowercase, letters and digits only - "Mobile No." and "mobile_no" match. */
    private static function normalise(string $label): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($label))) ?? '';
    }
}
