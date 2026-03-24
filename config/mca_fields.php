<?php

/**
 * MCA (Merchant Cash Advance) Predefined Field Definitions
 *
 * Controls auto-seeding behaviour: when a listed client creates a field and
 * specifies a section, ALL predefined fields for that section are inserted at
 * once instead of just the one the user typed.
 *
 * Extending for other industries:
 *   - Add a new top-level key under 'industries' (e.g. 'sba_loan')
 *   - List its sections and fields following the same structure
 *   - In LeadFieldService::seedIndustryFields(), pass the industry key
 *
 * field_type values (must be in CrmLabelController validation list):
 *   text | number | email | phone_number | date | textarea |
 *   dropdown | checkbox | radio | file
 *
 * Section keys must be the normalised form of the string the frontend sends
 * (lowercase, spaces → underscores, slashes/hyphens stripped).
 *   "Owner Information"       → owner_information
 *   "Documents / Verification"→ documents_verification
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Clients that receive MCA auto-seeding
    |--------------------------------------------------------------------------
    | Add or remove client IDs here — no code changes required.
    */
    'mca_client_ids' => [3],

    /*
    |--------------------------------------------------------------------------
    | MCA Field Definitions, keyed by normalised section name
    |--------------------------------------------------------------------------
    | Each entry:  ['label_name' => '...', 'field_key' => '...', 'field_type' => '...', 'required' => bool]
    */
    'sections' => [

        'owner_information' => [
            ['label_name' => 'Owner Name',           'field_key' => 'owner_name',           'field_type' => 'text',   'required' => false],
            ['label_name' => 'Date of Birth',        'field_key' => 'date_of_birth',         'field_type' => 'date',   'required' => false],
            ['label_name' => 'SSN',                  'field_key' => 'ssn',                   'field_type' => 'text',   'required' => false],
            ['label_name' => 'Ownership Percentage', 'field_key' => 'ownership_percentage',  'field_type' => 'number', 'required' => false],
        ],

        'business_information' => [
            ['label_name' => 'Legal Business Name',  'field_key' => 'legal_business_name',   'field_type' => 'text',   'required' => false],
            ['label_name' => 'DBA Name',             'field_key' => 'dba_name',              'field_type' => 'text',   'required' => false],
            ['label_name' => 'Business Start Date',  'field_key' => 'business_start_date',   'field_type' => 'date',   'required' => false],
            ['label_name' => 'Industry Type',        'field_key' => 'industry_type',         'field_type' => 'text',   'required' => false],
            ['label_name' => 'EIN',                  'field_key' => 'ein',                   'field_type' => 'text',   'required' => false],
        ],

        'funding_information' => [
            ['label_name' => 'Requested Amount',        'field_key' => 'requested_amount',        'field_type' => 'number',   'required' => false],
            ['label_name' => 'Use of Funds',            'field_key' => 'use_of_funds',            'field_type' => 'textarea', 'required' => false],
            ['label_name' => 'Existing Advance Balance','field_key' => 'existing_advance_balance','field_type' => 'number',   'required' => false],
        ],

        'contact_information' => [
            ['label_name' => 'Phone Number',    'field_key' => 'phone_number',     'field_type' => 'phone_number', 'required' => false],
            ['label_name' => 'Email',           'field_key' => 'email',            'field_type' => 'email',        'required' => false],
            ['label_name' => 'Business Address','field_key' => 'business_address', 'field_type' => 'text',         'required' => false],
            ['label_name' => 'City',            'field_key' => 'city',             'field_type' => 'text',         'required' => false],
            ['label_name' => 'State',           'field_key' => 'state',            'field_type' => 'text',         'required' => false],
            ['label_name' => 'Zip Code',        'field_key' => 'zip_code',         'field_type' => 'text',         'required' => false],
        ],

        'financial_information' => [
            ['label_name' => 'Monthly Revenue',      'field_key' => 'monthly_revenue',      'field_type' => 'number', 'required' => false],
            ['label_name' => 'Average Bank Balance', 'field_key' => 'average_bank_balance', 'field_type' => 'number', 'required' => false],
            ['label_name' => 'Number of NSF',        'field_key' => 'number_of_nsf',        'field_type' => 'number', 'required' => false],
            ['label_name' => 'Credit Score',         'field_key' => 'credit_score',         'field_type' => 'number', 'required' => false],
        ],

        // 'documents_verification' section removed — no longer available on the
        // CRM Lead Fields page. Re-add this block if the section is reinstated.
        // 'documents_verification' => [
        //     ['label_name' => 'Bank Statements Upload','field_key' => 'bank_statements_upload','field_type' => 'file', 'required' => false],
        //     ['label_name' => 'ID Proof Upload',       'field_key' => 'id_proof_upload',       'field_type' => 'file', 'required' => false],
        //     ['label_name' => 'Voided Check Upload',   'field_key' => 'voided_check_upload',   'field_type' => 'file', 'required' => false],
        // ],

        'custom_fields' => [
            ['label_name' => 'Notes',            'field_key' => 'notes',            'field_type' => 'textarea', 'required' => false],
            ['label_name' => 'Internal Remarks', 'field_key' => 'internal_remarks', 'field_type' => 'textarea', 'required' => false],
        ],

    ],
];
