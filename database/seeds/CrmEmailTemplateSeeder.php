<?php

use Illuminate\Database\Seeder;

class CrmEmailTemplateSeeder extends Seeder
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
                "template_name" => "Submission",   
                "template_html"=>'Salutations Team,<br />
                <br />
                Please get back to us on this deal.<br />
                <br />
                <br />
                Thank you!&nbsp;&nbsp;<br clear="all" />
                &nbsp;
                <div dir="ltr">
                <div dir="ltr">
                <div><b><font color="#000000">David &quot;Panda&quot; Bauman</font></b></div>
                
                <div><i><font color="#000000">Processing Head</font></i></div>
                
                <div><span style="color:#888888"><span style="background-color:#0000ff"><span style="background-color:#ffffff"><span style="color:#0000ff"><a href="http://bloomcapgroup.com" target="_blank">Bloom Capital Group</a></span></span></span></span></div>
                
                <div><span style="color:#888888">Website:&nbsp;<a href="http://bloomcapgroup.com/" style="color:#1155cc" target="_blank">bloomcapgroup.com</a></span></div>
                
                <div><span style="color:#888888"><img src="https://ci3.googleusercontent.com/mail-sig/AIorK4xPkWh9W8vZzw_K4ed16TBM_p0qNh8nuMPn14c9v8Tduq0xE2ac5iUrVVqwdjoJbYJ2jvzWDKs" style="width:200px; height:66px" /></span></div>
                
                <div><span style="color:#888888"><span style="font-size:12.8px; border-color:#222222"><span style="font-weight:700"><span style="font-family:-apple-system,&quot;Helvetica Neue&quot;"><span style="background-color:rgba(0,0,0,0)"><span style="color:#222222"><font face="Helvetica"><font style="font-family:Helvetica; font-size:0.625rem; background-color:rgba(0,0,0,0); border-color:#0000ff; color:#0000ff">NOTICE:&nbsp;</font></font></span></span></span></span></span><font style="font-size:12.8px; font-family:-apple-system,&quot;Helvetica Neue&quot;; background-color:rgba(0,0,0,0); border-color:#222222; color:#222222"><span style="font-size:9px; text-align:justify; border-color:#222222"><span style="background-color:rgba(0,0,0,0)"><font style="font-size:0.5625rem; background-color:rgba(0,0,0,0); border-color:#6aa84f; color:#6aa84f">The contents of this email message and any attachments</font></span></span></font><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="font-family:-apple-system,&quot;Helvetica Neue&quot;"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">&nbsp;are intended solely for the</span></span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="font-family:-apple-system,&quot;Helvetica Neue&quot;"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">&nbsp;addressee(s)</span></span></span></span></span>
                
                <div style="border-color:#222222"><span style="color:#888888"><span style="font-size:16px"><span style="word-spacing:1px"><span style="font-family:-apple-system,&quot;Helvetica Neue&quot;"><span style="background-color:rgba(0,0,0,0)"><span style="color:#222222"><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">&nbsp;and may contain confidential&nbsp;</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">and/or privileged information and may be legally protected</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">&nbsp;from disclosure.</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">&nbsp;If you</span></span></span></span></span></span></span></span></span></div>
                
                <div style="border-color:#222222"><span style="color:#888888"><span style="font-size:16px"><span style="word-spacing:1px"><span style="font-family:-apple-system,&quot;Helvetica Neue&quot;"><span style="background-color:rgba(0,0,0,0)"><span style="color:#222222"><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">are not the intended recipient of this message or their agent, or if</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">&nbsp;this message&nbsp;</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">has been addressed to you&nbsp;</span></span></span></span></span></span></span></span></span></div>
                
                <div dir="auto" style="border-color:#222222"><span style="color:#888888"><span style="font-size:16px"><span style="word-spacing:1px"><span style="font-family:-apple-system,&quot;Helvetica Neue&quot;"><span style="background-color:rgba(0,0,0,0)"><span style="color:#222222"><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">in&nbsp;</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">error</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">, please immediately&nbsp;</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">alert the sender by reply email and then&nbsp;</span></span></span><span style="font-size:0.5625rem; text-align:justify; border-color:#6aa84f"><span style="background-color:rgba(0,0,0,0)"><span style="color:#6aa84f">delete this message.</span></span></span></span></span></span></span></span></span></div>
                </div>
                </div>
                </div>
                ',
                "subject" => "NEW DEAL; [[legal_company_name]]", 
                "lead_status"=>"submitted",
  


            ],
            [
                "id" => 2,
                "template_name" => "Online Application", 
                "template_html"=>'<p>Dear [[first_name]],<br />
                <br />
                It was a pleasure speaking with you. Please click on our secure link where you can fill out our online application<br />
                as well as upload 3 months of your current bank statements.<br />
                <br />
                I personally look forward to getting you the capital you need to help your business. Also attached is the pdf if you decide<br />
                to print and email or fax back to me.<br />
                <br />
                Please click link below to get started:<br />
                [[unique_url]]<br />
                <br />
                [[company_name]]&nbsp;is dedicated to getting our clients the right working capital solutions for their business. We offer a<br />
                multitude of commercial finance options through our marketplace including but not limited to:<br />
                <br />
                <u><strong>Business Funding</strong></u></p>
                
                <ul>
                    <li>Small business loans</li>
                    <li>Government funding (non-bank SBA)</li>
                    <li>Cash flow loans</li>
                    <li>Merchant cash advances</li>
                    <li>Debt consolidation</li>
                </ul>
                
                <p><u><strong>Asset-based lending</strong></u></p>
                
                <ul>
                    <li>Receivables finance</li>
                    <li>Inventory finance</li>
                    <li>Equipment finance</li>
                    <li>Real estate finance</li>
                    <li>Intellectual property finance</li>
                </ul>
                
                <p><u><strong>Factoring</strong></u></p>
                
                <ul>
                    <li>Invoice factoring</li>
                    <li>Spot factoring</li>
                </ul>
                
                <p><u><strong>Trade finance</strong></u></p>
                
                <ul>
                    <li>Purchase order finance</li>
                    <li>Production finance</li>
                    <li>Contract finance</li>
                    <li>Supply chain finance</li>
                    <li>Trade credit</li>
                </ul>
                
                <p>because picking the right type of working capital facility with the right lender can make all the difference! So please provide us with<br />
                the following paperwork:<br />
                &nbsp;</p>
                
                <ul>
                    <li>One-page application (attached below) or click above and submit online</li>
                    <li>3 months of your most recent business bank statements</li>
                </ul>
                
                <p>and one of our senior fund managers will contact you within 24 hours to discuss:</p>
                
                <ul>
                    <li>What types of working capital are available to your company</li>
                    <li>The approximate cost of the financing</li>
                    <li>How long it will take to receive the funding</li>
                </ul>
                
                <p><br />
                Regards,<br />
                Funding Department<br />
                [[company_name]]<br />
                Office:&nbsp;<br />
                Fax: +1 (631) 557 1989<br />
                Email: funding@buzzcap.net</p>
                
                <p>NOTICE: This electronic transmission and any attachment are the confidential property of the sender, and the materials are privileged communications intended solely for the receipt, use, benefit, and information of the intended recipient indicated above. If you are not the intended recipient, you are hereby notified that any review, disclosure, copying, distribution, or the taking of any action in reliance on the contents of this electronic transmission is strictly prohibited, and may result in legal liability on your part. If you have received this email in error, please forward back to sender and destroy the electronic transmission.</p>
                ',
                "subject" => "Online Application", 
                "lead_status"=>"app_out",
     

  
            ],
         
       
            
        ];

        foreach ($lead_status as $lead) {
            $clients = \App\Model\Master\Client::all();
        
            foreach ($clients as $client) {
                // Check if the label already exists in the client's database
                $addLead = \App\Model\Client\CrmEmailTemplate::on("mysql_". $client->id)->find($lead["id"]);
        
                if (empty($addLead)) {
                    echo "Adding {$lead["id"]} to client_{$client->id}.label\n";
        
                    // Using firstOrCreate to add the label only if it doesn't exist
                    \App\Model\Client\CrmEmailTemplate::on("mysql_". $client->id)->firstOrCreate(
                        ['id' => $lead["id"]],
                        [
                            'template_name' => $lead["template_name"],
                            'template_html' => $lead["template_html"],
                            'subject' => $lead["subject"],
                            'lead_status' => $lead["lead_status"],
                          


                        ]
                    );
                } else {
                    echo "Label {$lead["id"]} already exists in client_{$client->id}.label\n";
                }
            }
        }  
    }
}
