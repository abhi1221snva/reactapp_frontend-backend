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
                table {
                    border-collapse: collapse;
                    width: 100%;
                    font-size:15px;
                  }
                  
                  th, td {
                    border: 1px solid black;
                    padding: 5px;
                    width: 50%;
                  }
                  
                  th {
                    background-color: #eee;
                  }
                  </style>
                  <table align="center" border="0" cellpadding="0" cellspacing="0" style="width:100%">
                      <tbody>
                          <tr>
                              <td colspan="2" style="vertical-align: middle; text-align: center;">
                              <h3>Online Application</h3>
                              </td>
                          </tr>
                          <tr>
                              <td style="vertical-align:top;text-align:center">_logo_<br />
                              _company_name_&nbsp; &nbsp;<br />
                              _company_address_ ,&nbsp;_city_ , _state_ ,&nbsp;_zipcode_</td>
                              <td style="vertical-align:top">
                              <table border="1" cellpadding="0" cellspacing="0" style="width:100%">
                                  <tbody>
                                      <tr>
                                          <td>Specialist :&nbsp;[first_name]&nbsp;[last_name]</td>
                                      </tr>
                                      <tr>
                                          <td>Phone :&nbsp;[mobile]</td>
                                      </tr>
                                      <tr>
                                          <td>Fax :&nbsp;</td>
                                      </tr>
                                      <tr>
                                          <td>Email :&nbsp;[email]</td>
                                      </tr>
                                  </tbody>
                              </table>
                              </td>
                          </tr>
                      </tbody>
                  </table>
                  
                  <table align="center" border="0" cellpadding="0" cellspacing="0" style="width:100%">
                      <thead>
                          <tr>
                              <th colspan="8">Business&nbsp;information</th>
                          </tr>
                      </thead>
                      <tbody>
                          <tr>
                              <td>Legal Business Name:&nbsp;[[legal_company_name]]</td>
                              <td colspan="7">DBA:&nbsp;[[dba]]</td>
                          </tr>
                          <tr>
                              <td>Street Address :&nbsp;[[business_address]]</td>
                              <td colspan="4">State :&nbsp;[[state]]&nbsp;</td>
                              <td colspan="2" style="white-space: nowrap;">City :&nbsp;[[city]]&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&nbsp;</td>
                              <td style="white-space: nowrap;">ZIP : [[zip_code]]&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</td>
                          </tr>
                          <tr>
                              <td>Start date:&nbsp;[[business_start_date]]</td>
                              <td colspan="7">EIN:&nbsp;[[ein]]</td>
                          </tr>
                          <tr>
                              <td>Industry type:&nbsp;[[industry]]</td>
                              <td colspan="7">Use of funds:&nbsp;[[use_of_funds]]</td>
                          </tr>
                          <tr>
                              <td>Amount requested: $[[amount_requested]]</td>
                              <td colspan="5">Business Phone :&nbsp;[[business_phone]]</td>
                              <td colspan="2">Fax:&nbsp;&nbsp;[[fax]]</td>
                          </tr>
                      </tbody>
                      <thead>
                          <tr>
                              <th colspan="8">Owner information</th>
                          </tr>
                      </thead>
                      <tbody>
                          <tr>
                              <td>First Name: [[first_name]]</td>
                              <td colspan="7">Last Name: [[last_name]]</td>
                          </tr>
                          <tr>
                              <td>Home Address:&nbsp;[[home_address]]</td>
                              <td colspan="4">State :&nbsp;[[state]]&nbsp;</td>
                              <td colspan="2">City :&nbsp;[[city]]&nbsp; &nbsp;&nbsp;</td>
                              <td>ZIP :&nbsp;[[home_zip]]</td>
                          </tr>
                          <tr>
                              <td>Cell Phone:&nbsp;[[mobile]]&nbsp;</td>
                              <td colspan="7">Ownership Percentage :&nbsp;[[ownership_percentage]]</td>
                          </tr>
                          <tr>
                              <td>Email:&nbsp;[[email]]</td>
                              <td colspan="7">SSN:&nbsp;[[ssn]]</td>
                          </tr>
                          <tr>
                              <td>Credit Score :&nbsp;[[credit_score]]</td>
                              <td colspan="7">Date Of Birth :&nbsp;[[dob]]</td>
                          </tr>
                      </tbody>
                  </table>
                  
                  <table align="center" border="0" cellpadding="0" cellspacing="0" style="width:100%">
                      <tbody>
                          <tr>
                              <td style="text-align: justify;font-size: 12px;">By signing below, each of the above listed business and business owner/officer (individually and collectively, &quot;Applicant&quot;) certify that the Applicant is an owner of the above named business and that all information provided in the application is true and accurate. Applicant shall immediately notify&nbsp;_company_name_&nbsp;dba&nbsp;_company_name_&nbsp;of any change in such information or financial condition. Applicant authorizes _company_name_&nbsp;to share this application with each of its representatives, successors, assigns and designees (&quot;Assignees&quot;) or any other parties that may be involved with the extension of credit pursuant to this application including those who offer commercial loans having daily repayment features or purchases of future receivables including Merchant Cash Advance transactions, including without limitation the application therefor (collectively, &quot;Transactions ). Applicant further authorizes&nbsp;_company_name_&nbsp;and all Assignees to request and receive any third party consumer or personal , business and investigative reports and other information about Applicant, including credit card processor statements and bank statements, from one or more consumer reporting agencies, such as TransUnion, Experian , and Equifax, and from other credit bureaus, banks, creditors and other third parties. Applicant authorizes Bloom Capital Group to transmit this form, along with any other foregoing information obtained in connection with this application, to any or all of the Assignees for the foregoing purpose. You also consent to the release, by any creditor or financial institution , of any information relating to any of you , to&nbsp;_company_name_&nbsp;and to each of the Asignees, on its own behalf. Applicant waives and releases any claims against Recipients and any information-providers arising from any act or omission relating to the requesting, receiving or release of the information obtained in connection with this application.</td>
                          </tr>
                      </tbody>
                  </table>
                  
                  <table align="center" border="0" cellpadding="0" cellspacing="0" style="width:100%">
                      <tbody>
                          <tr>
                              <td style="width:25px;">[[signature_image]]</td>
                              <td colspan="4" style="width:25px;">[[lead_created_at]]</td>
                              <td colspan="2" style="width:25px;">&nbsp;</td>
                              <td style="width:25px;">&nbsp;</td>
                          </tr>
                          <tr>
                              <td style="width:25px;">Owner&#39;s signature</td>
                              <td colspan="4" style="width:25px;">Date</td>
                              <td colspan="2" style="width:25px;">Second Owner&#39;s signature</td>
                              <td style="width:25px;">Date</td>
                          </tr>
                      </tbody>
                  ', 
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
