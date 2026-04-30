<?php

use Illuminate\Database\Seeder;

class CrmCustomTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $lead_status = [
            [
                "id" => 1,
                "template_name" => "Signed Application",   
                "template_html" => '<style type="text/css">
body { width: 100%; margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #1e293b; }
table { width: 100%; border-collapse: collapse; }
.data-table { table-layout: fixed; }
.data-table th, .data-table td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; vertical-align: top; word-wrap: break-word; }
.data-table th { background-color: #f1f5f9; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #334155; font-weight: 700; }
.layout-table, .layout-table > tbody > tr > td { border: none; }
.layout-table > tbody > tr > td { vertical-align: top; }
.label { color: #64748b; font-weight: 600; }
</style>

<!-- Header -->
<table class="layout-table" style="width:100%; margin-bottom:10px;">
<tbody><tr>
<td style="width:50%; text-align:center; vertical-align:middle; padding:8px;">
[[company_logo]]
<div style="font-weight:700; font-size:14px; margin-top:4px;">[[company_name]]</div>
<div style="font-size:11px; color:#64748b;">[[office_address]], [[office_city]], [[office_state]] [[office_zip]]</div>
</td>
<td style="width:50%; padding:8px;">
<table class="data-table" style="width:100%;">
<tbody>
<tr><td><span class="label">Specialist:</span> [[specialist_name]]</td></tr>
<tr><td><span class="label">Phone:</span> [[specialist_phone]]</td></tr>
<tr><td><span class="label">Fax:</span> [[specialist_fax]]</td></tr>
<tr><td><span class="label">Email:</span> [[specialist_email]]</td></tr>
</tbody>
</table>
</td>
</tr></tbody>
</table>

<h3 style="text-align:center; margin-bottom:8px;">Online Application</h3>

<!-- Business Information — full width -->
<table class="data-table" style="width:100%; margin-bottom:8px;">
<thead><tr><th colspan="4">Business Information</th></tr></thead>
<tbody>
<tr>
<td colspan="2"><span class="label">Legal Business Name:</span> [[legal_company_name]]</td>
<td colspan="2"><span class="label">DBA:</span> [[dba]]</td>
</tr>
<tr>
<td><span class="label">Street Address:</span> [[business_address]]</td>
<td><span class="label">City:</span> [[city]]</td>
<td><span class="label">State:</span> [[state]]</td>
<td><span class="label">ZIP:</span> [[zip_code]]</td>
</tr>
<tr>
<td colspan="2"><span class="label">Start Date:</span> [[business_start_date]]</td>
<td colspan="2"><span class="label">EIN:</span> [[ein]]</td>
</tr>
<tr>
<td colspan="2"><span class="label">Industry Type:</span> [[industry]]</td>
<td colspan="2"><span class="label">Use of Funds:</span> [[use_of_funds]]</td>
</tr>
<tr>
<td colspan="2"><span class="label">Amount Requested:</span> $[[amount_requested]]</td>
<td><span class="label">Business Phone:</span> [[business_phone]]</td>
<td><span class="label">Fax:</span> [[fax]]</td>
</tr>
</tbody>
</table>

<!-- Owner Section — side-by-side when both exist; full-width when single -->
<table class="layout-table" style="width:100%; margin-bottom:8px;">
<tbody><tr>
<td class="owner-1-col" style="width:50%; padding-right:4px;">
<table class="data-table" style="width:100%;">
<thead><tr><th colspan="2">Owner Information</th></tr></thead>
<tbody>
<tr>
<td><span class="label">First Name:</span> [[first_name]]</td>
<td><span class="label">Last Name:</span> [[last_name]]</td>
</tr>
<tr><td colspan="2"><span class="label">Home Address:</span> [[home_address]]</td></tr>
<tr>
<td><span class="label">City:</span> [[home_city]]</td>
<td><span class="label">State:</span> [[home_state]] &nbsp;<span class="label">ZIP:</span> [[owner_zipcode]]</td>
</tr>
<tr>
<td><span class="label">Cell Phone:</span> [[mobile]]</td>
<td><span class="label">Ownership %:</span> [[ownership_percentage]]</td>
</tr>
<tr>
<td><span class="label">Email:</span> [[email]]</td>
<td><span class="label">SSN:</span> [[ssn]]</td>
</tr>
<tr>
<td><span class="label">Credit Score:</span> [[credit_score]]</td>
<td><span class="label">Date of Birth:</span> [[dob]]</td>
</tr>
</tbody>
</table>
</td>
<td class="owner-2-col" style="width:50%; padding-left:4px;">
<table class="data-table" style="width:100%;">
<thead><tr><th colspan="2">Second Owner Information</th></tr></thead>
<tbody>
<tr>
<td><span class="label">First Name:</span> [[owner_2_first_name]]</td>
<td><span class="label">Last Name:</span> [[owner_2_last_name]]</td>
</tr>
<tr><td colspan="2"><span class="label">Home Address:</span> [[owner_2_home_address]]</td></tr>
<tr>
<td><span class="label">City:</span> [[owner_2_home_city]]</td>
<td><span class="label">State:</span> [[owner_2_home_state]] &nbsp;<span class="label">ZIP:</span> [[owner_2_zipcode]]</td>
</tr>
<tr>
<td><span class="label">Cell Phone:</span> [[owner_2_mobile]]</td>
<td><span class="label">Ownership %:</span> [[owner_2_ownership_percentage]]</td>
</tr>
<tr>
<td><span class="label">Email:</span> [[owner_2_email]]</td>
<td><span class="label">SSN:</span> [[owner_2_ssn]]</td>
</tr>
<tr>
<td><span class="label">Credit Score:</span> [[owner_2_credit_score]]</td>
<td><span class="label">Date of Birth:</span> [[owner_2_dob]]</td>
</tr>
</tbody>
</table>
</td>
</tr></tbody>
</table>

<!-- Disclaimer -->
<div style="font-size:10px; color:#475569; text-align:justify; line-height:1.5; padding:8px 0; border-top:1px solid #e5e7eb;">
By signing below, each of the above listed business and business owner/officer (individually and collectively, &quot;Applicant&quot;) certify that the Applicant is an owner of the above named business and that all information provided in the application is true and accurate. Applicant shall immediately notify [[company_name]] dba [[company_name]] of any change in such information or financial condition. Applicant authorizes [[company_name]] to share this application with each of its representatives, successors, assigns and designees (&quot;Assignees&quot;) or any other parties that may be involved with the extension of credit pursuant to this application including those who offer commercial loans having daily repayment features or purchases of future receivables including Merchant Cash Advance transactions, including without limitation the application therefor (collectively, &quot;Transactions&quot;). Applicant further authorizes [[company_name]] and all Assignees to request and receive any third party consumer or personal, business and investigative reports and other information about Applicant, including credit card processor statements and bank statements, from one or more consumer reporting agencies, such as TransUnion, Experian, and Equifax, and from other credit bureaus, banks, creditors and other third parties. Applicant authorizes [[company_name]] to transmit this form, along with any other foregoing information obtained in connection with this application, to any or all of the Assignees for the foregoing purpose. You also consent to the release, by any creditor or financial institution, of any information relating to any of you, to [[company_name]] and to each of the Assignees, on its own behalf. Applicant waives and releases any claims against Recipients and any information-providers arising from any act or omission relating to the requesting, receiving or release of the information obtained in connection with this application.
</div>

<!-- Signatures -->
<table class="layout-table" style="width:100%; margin-top:10px;">
<tbody>
<tr>
<td style="width:25%; border-top:1px solid #1e293b; padding-top:6px;">[[signature_image]]</td>
<td style="width:25%; border-top:1px solid #1e293b; padding-top:6px;">[[signature_date]]</td>
<td style="width:25%; border-top:1px solid #1e293b; padding-top:6px;">[[owner_2_signature_image]]</td>
<td style="width:25%; border-top:1px solid #1e293b; padding-top:6px;">[[owner_2_signature_date]]</td>
</tr>
<tr>
<td style="width:25%; font-size:10px; color:#64748b;">Owner&#39;s Signature</td>
<td style="width:25%; font-size:10px; color:#64748b;">Date</td>
<td style="width:25%; font-size:10px; color:#64748b;">Second Owner&#39;s Signature</td>
<td style="width:25%; font-size:10px; color:#64748b;">Date</td>
</tr>
</tbody>
</table>', 
                "custom_type"=>"signature_application",
  


            ],
         
       
            
        ];

        foreach ($lead_status as $lead) {
            $clients = \App\Model\Master\Client::all();
        
            foreach ($clients as $client) {
                // Check if the label already exists in the client's database
                $addLead = \App\Model\Client\CustomTemplates::on("mysql_". $client->id)->find($lead["id"]);
        
                if (empty($addLead)) {
                    echo "Adding {$lead["id"]} to client_{$client->id}.label\n";
        
                    // Using firstOrCreate to add the label only if it doesn't exist
                    \App\Model\Client\CustomTemplates::on("mysql_".$client->id)->firstOrCreate(
                        ['id' => $lead["id"]],
                        [
                            'template_name' => $lead["template_name"],
                            'template_html' => $lead["template_html"],
                            'custom_type' => $lead["custom_type"],



                        ]
                    );
                } else {
                    echo "Label {$lead["id"]} already exists in client_{$client->id}.label\n";
                }
            }
        } 
    }
}
