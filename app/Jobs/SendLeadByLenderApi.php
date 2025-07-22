<?php
namespace App\Jobs;
use Illuminate\Support\Facades\Log;
use App\Model\Client\CrmLenderAPis;
use App\Model\Client\CrmLenderApiLabels;
use App\Model\Client\Documents;
use App\Model\Client\Lead;
use App\Model\Client\Fcs;
use App\Model\Client\FcsLenderList;
use App\Model\Client\CrmLabel;
use App\Model\Client\ApiLog;  // Import the ApiLog model
use App\Model\Client\CrmLeadLenderApi;  // Import the ApiLog model
use App\Model\Client\Notification;
use App\Model\Client\SystemSetting;
use App\Model\Client\SmtpSetting;
use App\Model\User;

use App\Model\Client\EmailSetting;



use App\Model\Master\AreaCodeList;
use Carbon\Carbon;
use Exception;

use App\Mail\SystemNotificationMail;
use App\Services\MailService;

class SendLeadByLenderApi extends Job
{
    private $clientId;
    private $data;
    private $emailType;

    public function __construct(int $clientId, array $data, $emailType)
    {
        $this->clientId = $clientId;
        $this->data = $data;
        $this->emailType = $emailType;
        Log::info("Initializing SendDataOnLenderApi for client ID: $clientId", $data);
    }

    public function handle()
    {
        $crmLeadId = $this->data['lead_id'] ?? null;
        $lenderIds = $this->data['lender_id'] ?? [];
        $lenderNames = $this->data['lender_name'] ?? [];
        $userId = $this->data['user_id'] ?? null;

        if (!$crmLeadId || empty($lenderIds)) {
            Log::error("Invalid data provided: lead_id or lender_id is missing.");
            return;
        }

        $uniqueCredentials = [];

        foreach ($lenderIds as $key => $request)
        {
            $lenderName = $lenderNames[$key]['lender_name'] ?? null;
            $lenderId = $request['lender_id'] ?? null;
            if (!$lenderId) 
            {
                Log::warning("Skipping lender with missing lender_id.");
                continue;
            }

            $emailLender = CrmLenderAPis::on("mysql_{$this->clientId}")->where('crm_lender_id', $lenderId)->first();

            if (!$emailLender) 
            {
                Log::warning("No lender API details found for lender ID: $lenderId");
                continue;
            }

            if($emailLender->type == 'ondeck')
            {
                $credentialsKey = "{$emailLender->username}:{$emailLender->password}:{$emailLender->api_key}";
                if (isset($uniqueCredentials[$credentialsKey])) 
                {
                    Log::info("Skipping duplicate credentials for lender ID: $lenderId");
                    continue;
                }
            }
            else
            if($emailLender->type == 'credibly')
            {
                $credentialsKey = "{$emailLender->api_key}";
                if (isset($uniqueCredentials[$credentialsKey])) 
                {
                    Log::info("Skipping duplicate credentials for lender ID: $lenderId");
                    continue;
                }
            }
            else
            if($emailLender->type == 'bitty_advance')
            {
                $credentialsKey = "{$emailLender->api_key}";
                if (isset($uniqueCredentials[$credentialsKey])) 
                {
                    Log::info("Skipping duplicate credentials for lender ID: $lenderId");
                    continue;
                }
            }
            else
            if($emailLender->type == 'fox_partner')
            {
                $credentialsKey = "{$emailLender->username}:{$emailLender->password}";
                if (isset($uniqueCredentials[$credentialsKey])) 
                {
                    Log::info("Skipping duplicate credentials for lender ID: $lenderId");
                    continue;
                }
            }

            else
            if($emailLender->type == 'lendini')
            {
                $credentialsKey = "{$emailLender->username}:{$emailLender->password}";
                if (isset($uniqueCredentials[$credentialsKey])) 
                {
                    Log::info("Skipping duplicate credentials for lender ID: $lenderId");
                    continue;
                }
            }

            else
            if($emailLender->type == 'specialty')
            {
                $credentialsKey = "{$emailLender->api_key}";
                if (isset($uniqueCredentials[$credentialsKey])) 
                {
                    Log::info("Skipping duplicate credentials for lender ID: $lenderId");
                    continue;
                }
            }

            else
            if($emailLender->type == 'forward_financing')
            {
                $credentialsKey = "{$emailLender->api_key}";
                if (isset($uniqueCredentials[$credentialsKey])) 
                {
                    Log::info("Skipping duplicate credentials for lender ID: $lenderId");
                    continue;
                }
            }


            else
            if($emailLender->type == 'cancapital')
            {
                $credentialsKey = "{$emailLender->username}:{$emailLender->password}";
                if (isset($uniqueCredentials[$credentialsKey])) 
                {
                    Log::info("Skipping duplicate credentials for lender ID: $lenderId");
                    continue;
                }
            }

              else
            if($emailLender->type == 'biz2credit')
            {

            $credentialsKey = true;

                
            }


            

            $uniqueCredentials[$credentialsKey] = true;

            $leadLenderRecord = CrmLeadLenderApi::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->where('lender_id', $lenderId)->first();

            $crmLeadData = Lead::on("mysql_{$this->clientId}")->find($crmLeadId);
            if (!$crmLeadData)
            {
                Log::error("Lead not found for ID: $crmLeadId");
                continue;
            }

            $crmLabels = CrmLabel::on("mysql_{$this->clientId}")->get();
            $body = [];

            foreach ($crmLabels as $label)
            {
                $columnName = $label->column_name;
                $labelDataType = $label->data_type;
                if ($labelDataType === 'phone_number') 
                {
                    $phoneNumber = str_replace(['(', ')', '_', '-', ' '], '', $crmLeadData->{$columnName});
                    $body[$label->label_title_url] = $phoneNumber;
                } 
                elseif ($labelDataType === 'date') 
                {
                    $body[$label->label_title_url] = $this->formatDate($crmLeadData->{$columnName}, $columnName);
                }
                elseif ($labelDataType === 'select_state' || $label->column_name =='state' ) 
                {
                    //$state_code = AreaCodeList::on("master")->where('state_name', $crmLeadData->{$columnName})->value('state_code');

                    $state_code = AreaCodeList::on("master")->where(function($query) use ($crmLeadData, $columnName) {$query->where('state_name', $crmLeadData->{$columnName})->orWhere('state_code', $crmLeadData->{$columnName});})->value('state_code');
                    
                    $body[$label->label_title_url] = $state_code;//$this->formatDate($crmLeadData->{$columnName}, $columnName);
                }
                else
                {
                    $body[$label->label_title_url] = $crmLeadData->{$columnName};
                }
            }

            //echo "<pre>";print_r($body);die;

            $array = $body;

            $flattenJson = function ($data, $parentKey = '') use (&$flattenJson) 
            {
                $items = [];
                foreach ($data as $key => $value)
                {
                    $newKey = $parentKey ? $parentKey . '.' . $key : $key;
                    if (is_array($value)) 
                    {
                        $items = array_merge($items, $flattenJson($value, $newKey));
                    }
                    else 
                    {
                        $items[] = [$newKey => $value];
                    }
                }
                return $items;
            };

            $arrLabels = $flattenJson($array);

            

           // echo "<pre>";print_r($arrLabels);die;

            if($emailLender->type == 'ondeck')
            {
                foreach ($arrLabels as $key => $arrLabel) 
                {
                    foreach ($arrLabel as $originalKey => $value) 
                    {
                        $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
                        if ($objLabelFound) 
                        {
                            $crm_label_id = $objLabelFound->id;
                            $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id', $crm_label_id)->whereNotNull('ondeck_label')->first();

                            if ($label) 
                            {
                                $ondeck_label = $label->ondeck_label;
                                if (isset($updatedArray[$ondeck_label])) 
                                {
                                    $updatedArray[$ondeck_label] .= ' ' . $value;
                                } 
                                else
                                {
                                    $updatedArray[$ondeck_label] = $value;
                                }
                            }
                        }
                    }
                }

            //echo "<pre>";print_r($updatedArray);die;

                $data = 
                [
                    "business" => 
                    [
                        "address" => 
                        [
                            "state" => $updatedArray['business.address.state'],
                            "city" => $updatedArray['business.address.city'],
                            "zipCode" => $updatedArray['business.address.zipCode'],
                            "addressLine1" => $updatedArray['business.address']
                        ],

                        "phone" => $updatedArray['business.phone'],
                        "businessInceptionDate" => $updatedArray['business.businessInceptionDate'],
                        "taxID" => preg_replace('/\D/', '', $updatedArray['business.taxID']),
                        "name" => $updatedArray['business.name']
                    ],

                    "owners" => 
                    [
                        [
                            "dateOfBirth" => $updatedArray['owners.dateOfBirth'],
                            "homeAddress" => 
                            [
                                "state" => $updatedArray['owners.homeAddress.state'],
                                "city" => $updatedArray['owners.homeAddress.city'],
                                "zipCode" => $updatedArray['owners.homeAddress.zipCode'],
                                "addressLine1" => $updatedArray['owners.homeAddress.address']
                            ],

                            "email" => $updatedArray['owners.email'],
                            "homePhone" => $updatedArray['owners.homePhone'],
                            "ownershipPercentage" => $updatedArray['owners.ownershipPercentage'],
                            "ssn" => preg_replace('/\D/', '', $updatedArray['owners.ssn']),
                            "name" => $updatedArray['owners.name']
                        ]
                    ],

                    "selfReported" => 
                    [
                        "revenue" => $updatedArray['selfReported.revenue'],
                        "averageBalance" => $updatedArray['selfReported.averageBalance']
                    ]   
                ];


               // echo "<pre>";print_r($data);die;


                $auth = base64_encode("{$emailLender->username}:{$emailLender->password}");
                if ($leadLenderRecord && $leadLenderRecord->businessID) 
                {
                    $url = "{$emailLender->url}application/{$leadLenderRecord->businessID}";  // Update API endpoint with businessID
                    $httpMethod = 'PUT';
                    $business = $leadLenderRecord->businessID;
                } 
                else
                {
                    $url = "{$emailLender->url}application";  // Create API endpoint
                    $httpMethod = 'POST';
                    $business = "";
                }

                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => $httpMethod,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Apikey: ' . $emailLender->api_key,
                        'Authorization: Basic ' . $auth
                    ],
                ]);

                $response = curl_exec($curl);
                $response_data = json_decode($response, true);

                $message = 'Lender <b>'.$lenderName. ' :</b>';

                if (isset($response_data['success']) && $response_data['success'] === false && isset($response_data['errorMessages']) && is_array($response_data['errorMessages'])) 
                {
                    for ($i = 0; $i < count($response_data['errorMessages']); $i++) 
                    {
                        $message .=$response_data['errorMessages'][$i] . PHP_EOL .' ( onDeck )';
                    }
                }

                else
                    if (isset($response_data['success']) && isset($response_data['errorMessages']) && is_array($response_data['errorMessages'])) 
                    {
                        $message .=$response_data['errorMessages'][$i] . PHP_EOL .' ( onDeck )';
                    }
                    else
                    {
                        $message = 'Lender <b>'.$lenderName. '</b> :The Application has been submitted successfully'.' ( onDeck )';
                    }

                $businessID = $response_data['businessID'] ?? $business;

                $Notification = new Notification();
                $Notification->setConnection("mysql_{$this->clientId}");
                $Notification->user_id = $userId;
                $Notification->lead_id = $crmLeadId;
                $Notification->message = $message;
                $Notification->type = '2';
                $Notification->saveOrFail();

                $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $clientIp = request()->ip();
                $userAgent = request()->header('User-Agent');


                ApiLog::create([
                    'endpoint' => $url,
                    'client_id' => $this->clientId,
                    'lender_id' => $lenderId,
                    'lead_id' => $crmLeadId,
                    'request_data' => json_encode($data),
                    'response_data' => $response,
                    'status_code' => $statusCode,
                    'request_ip' => $clientIp,
                    'user_agent' => $userAgent,
                    'businessID' => $businessID,
                    'created_at' => Carbon::now(),
                ]);

                if($businessID)
                {
                    CrmLeadLenderApi::on("mysql_{$this->clientId}")->updateOrCreate(
                        ['lead_id' => $crmLeadId, 'lender_id' => $lenderId, 'client_id' => $this->clientId, 'lender_api_type' => 'ondeck'],
                        ['businessID' => $businessID, 'updated_at' => Carbon::now()]
                    );
                }

                if (curl_errno($curl)) 
                { 
                    Log::error("CURL Error for lender ID: $lenderId - " . curl_error($curl));
                }
                else 
                {
                    Log::info("Response for lender ID: $lenderId", ['response' => $response]);
                }

                curl_close($curl);

                $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
                $fileNames = [];

                if (app()->environment() == "local") 
                {
                    $rootPath = 'C:\xampp\htdocs\subscription_signup\frontend_beta\public\uploads/';
                }
                else
                {
                    $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
                }



                foreach ($document_lists as $key => $document) 
                {
                    $filePaths[$key]['file_path'] = $rootPath.$document->file_name;
                    $filePaths[$key]['title'] = $document->document_type;

                }

                //echo "<pre>";print_r($filePaths);die;

                $url = "{$emailLender->url}application/{$businessID}/documents";

                if($filePaths)
                {
                    $Notification = new Notification();
                    $Notification->setConnection("mysql_{$this->clientId}");

                    $Notification->user_id = $userId;
                    $Notification->lead_id = $crmLeadId;
                    $Notification->type = '2';

                    foreach ($filePaths as  $filePath) 
                    {
                        if (!file_exists($filePath['file_path'])) 
                        {
                            $message = 'File Not Found '.$filePath['file_path'];
                            $Notification->saveOrFail();
                            continue;
                        }

                        $ch = curl_init();

                        $postFields = 
                        [
                            'file' => new \CURLFile($filePath['file_path']), // Attach the document with the 'file' field name
                            'description' => $filePath['title'] // Optional description for the document
                        ];

                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);

                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Basic ' . $auth,
                            'Apikey: ' . $emailLender->api_key
                        ]);

                        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 seconds timeout
                        $response = curl_exec($ch);

                        $data = json_decode($response, true);
                        $fullPath = $filePath['file_path'];
                        
                        $fileName = str_replace($rootPath, '', $fullPath);
                        $file_name = $fileName; // Outputs: example.jpg

                        if (curl_errno($ch)) 
                        {
                            echo "cURL Error for file $filePath: " . curl_error($ch) . "\n";
                        } 
                        else
                        {
                            $responseData = json_decode($response, true);
                            if (isset($responseData['success']) && $responseData['success']) 
                            {
                                $status_code = '200';
                                $Notification->message = 'Lender <b>'.$lenderName.'</b> Document Sent file name is '.$file_name .' (onDeck)';
                            }
                            else 
                            {
                                $status_code = '401';
                                $Notification->message = 'Lender <b>'.$lenderName.'</b> Document Not Sent file name is '. $file_name.' (onDeck)';
                            }

                            $Notification->saveOrFail();
                            $clientIp = request()->ip();
                            $userAgent = request()->header('User-Agent');

                            ApiLog::create([
                                'endpoint' => $url,
                                'client_id' => $this->clientId,
                                'lender_id' => $lenderId,
                                'lead_id' => $crmLeadId,
                                'request_data' => json_encode($postFields),
                                'response_data' => $response,
                                'status_code' => $status_code,
                                'request_ip' => $clientIp,
                                'user_agent' => $userAgent,
                                'businessID' => $businessID,
                                'created_at' => Carbon::now(),
                            ]);
                        }
                        curl_close($ch);
                    }
                }

                $this->sendEmail($message,$userId,$crmLeadId,$this->clientId,'Ondeck');

            }
            else
                if($emailLender->type == 'credibly')
            
            {
            $this->credibly($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId);

            }
            else
            if($emailLender->type == 'bitty_advance')
        
        {
        $this->bittyAdvance($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId);

        }
            else
            if($emailLender->type == 'fox_partner')
            
            {
            $this->foxPartner($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId);

            }

        else
        if($emailLender->type == 'lendini')
        {
            $this->lendiniApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId);

        }

        else
        if($emailLender->type == 'specialty')
        {
            $this->specialityApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId);

        }

        else
        if($emailLender->type == 'forward_financing')
        {
            $this->forwardFinancingApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId);

        }

        else
        if($emailLender->type == 'cancapital')
        {
            $this->canCapitalApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId);

        }

        else
        if($emailLender->type == 'biz2credit')
        {
            $this->biz2CreditApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId);

        }

            
        }
    }

    private function formatDate($dateInput, $columnName)
    {
        if ($dateInput) {
            try {
                return Carbon::parse($dateInput)->format('Y-m-d');
            } catch (Exception $e) {
                Log::error("Date Parsing Error for {$columnName} with value '{$dateInput}': " . $e->getMessage());
            }
        }
        return null;
    }

    private function credibly($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId)
    {

        $documentTypes = [
    "Bank Statements",
    "Signed Application",
    "Driver's License",
    "Voided Check",
    "Business Lease/Business Mortgage",
    "Landlord Contact Info",
    "Most Recent Tax Return",
    "Credit Card Processing Statements",
    "Payoff Letter for Current Funding",
    "Trade Reference",
    "Bank Verification",
    "Lien/Judgment payment plan or satisfaction letter",
    "Misc License",
    "Business License",
    "Trade License",
    "Zero Balance Letter",
    "Multiple Location Agreement",
    "Proof of Majority Ownership",
    "Bill of Sale for Business Purchase",
    "Seller Contact Info",
    "Franchise Contact Info",
    "Site Inspection",
    "Levy/Legal Order/Garnishment Documentation",
    "Copy of Social Security Card",
    "A/R Aging Report",
    "Proof of U.S. Residency",
    "Payment History for Current MCA",
    "UCC Lien Filing",
    "Third Party Authorization",
    "Credit Card Processing Termination Letter",
    "Verbal Merchant Lease Agreement",
    "Lockbox Documents",
    "Screenshot of New MID and Recent Batching",
    "Split Agreement",
    "Correctly Executed Contracts",
    "vACH Addendum",
    "Primary Email Address",
    "Release of Information",
    "Early Remit Addendum",
    "Assignment and Assumption of Purchase Agreement",
    "Signed Contract"
];
        $SystemSetting = SystemSetting::on("mysql_{$this->clientId}")->get()->first();;
        if($SystemSetting)
        {
            $rootPath = $SystemSetting->company_domain.'uploads/';
        }
        else
        {
            $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
        }

        $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
        $fileNames = [];

        foreach ($document_lists as $key => $document) 
        {
            $inputDocumentType = $document->document_type; 
            $normalizedInput = $this->normalizeInput($inputDocumentType);
            $closestMatch = $this->findClosestMatch($normalizedInput, $documentTypes);

            $finalDocumentType = $closestMatch ?? "Signed Application";
            

            $filePaths[$key]['name'] = $document->file_name;
            $filePaths[$key]['url'] = $rootPath.$document->file_name;
            $filePaths[$key]['type'] = $finalDocumentType;

        }

        


        //echo "<pre>";print_r($filePaths);die;
        foreach ($arrLabels as $key => $arrLabel) 
        {
            foreach ($arrLabel as $originalKey => $value) 
            {
                $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
                if ($objLabelFound) 
                {
                    $crm_label_id = $objLabelFound->id;
                    $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id', $crm_label_id)->whereNotNull('credibly_label')->first();

                    if ($label) 
                    {
                        $credibly_label = $label->credibly_label;
                        if (isset($updatedArray[$credibly_label])) 
                        {
                            $updatedArrayCredibly[$credibly_label] .= ' ' . $value;
                        } 
                        else
                        {
                            $updatedArrayCredibly[$credibly_label] = $value;
                        }
                    }
                }
            }
        }


        if (!isset($updatedArrayCredibly['business_overview.federal_id'])) 
        {
            $updatedArrayCredibly['business_overview.federal_id'] = preg_replace('/\D/', '', $updatedArrayCredibly['principals.ssn']);
        }
       // echo "<pre>";print_r($updatedArrayCredibly);die;

        $amount_requested = $updatedArrayCredibly['application_info.amount_requested']; 
        $amount_requested_final = preg_replace('/,/', '', $amount_requested, 1);
        //$formatted = number_format((float)$number, 2, '.', '');
        //$amount_requested_final = $formatted; 
        
        $data = 
        [
            "business_overview" => 
            [
                "dba" => $updatedArrayCredibly['business_overview.dba'],
                "legal_name" => $updatedArrayCredibly['business_overview.legal_name'],
                "state_of_incorporation" => $updatedArrayCredibly['business_location.address.state'],
                "date_established" => $updatedArrayCredibly['business_overview.date_established'],
                "naics" => 123456,
                "federal_id" => $updatedArrayCredibly['business_overview.federal_id'],
            ],

            "business_location" => 
            [
                "address" => 
                [
                    [
                        "address" => $updatedArrayCredibly['business_location.address.address'],
                        "city" => $updatedArrayCredibly['business_location.address.city'],
                        "state" => $updatedArrayCredibly['business_location.address.state'],
                        "postal_code" => $updatedArrayCredibly['business_location.address.postal_code'],
                    ],
                ],
            ],

            "business_contact" => 
            [
                "phone" => $updatedArrayCredibly['business_contact.phone'],
                "email" => $updatedArrayCredibly['business_contact.email'],
            ],

            "business_profile" => 
            [
                "ownership" => $updatedArrayCredibly['business_overview.dba'],
            ],
            "principals" => 
            [
                [
                "name_last" => $updatedArrayCredibly['principals.name_last'],
                "name_first" => $updatedArrayCredibly['principals.name_first'],
                "percent_ownership" => $updatedArrayCredibly['principals.percent_ownership'],
                "ssn" => preg_replace('/\D/', '', $updatedArrayCredibly['principals.ssn']),
                "address" => 
                [
                    "address" => $updatedArrayCredibly['principals.address.address'],
                    "city" => $updatedArrayCredibly['principals.address.city'],
                    "state" => $updatedArrayCredibly['principals.address.state'],
                    "postal_code" => $updatedArrayCredibly['principals.address.postal_code'],
                ],
                "dob" => $updatedArrayCredibly['principals.dob'],
            ],
        ],

        "application_info" => 
        [
            "product_requested" => "ach",
            "amount_requested" => $amount_requested_final,
        ],

        "files" => $filePaths
    ];

    //echo "<pre>";print_r($data);die;

    $url = "{$emailLender->url}submission-api/submitApplication";  // Create API endpoint
    $token = $emailLender->api_key;

$curl = curl_init();
    
// Set cURL options
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json'
    ],
]);

// Execute the request
$response = curl_exec($curl);

// Check for errors
/*if (curl_errno($curl)) {
    echo "cURL error: " . curl_error($curl);
} else {
    echo "API Response: " . $response;
}

die;*/
// Close cURL
curl_close($curl);

$response_data = json_decode($response, true);


                $message = 'Lender <b>'.$lenderName. ' :</b>';

                $responseId = $response_data['response_id'] ?? null;

                $statusCode = '';


                if ($responseId) {
                    $statusCode = 201;
                    //echo "Response ID: " . $responseId;
                    $decision = $response_data['decisions']['decision'] ?? null;
                    $message .= $decision.' Application has been sent successfully ( credibly )';
                } else {
                    $responseId ='';

                    $declineReasons = $data['declineReasons'] ?? null;
                    if ($declineReasons && is_array($declineReasons)) {
                    $statusCode = 301;
                    $responseId ='';


                        $message .= "Decline Reasons: " . implode(", ", $declineReasons).'( credibly )';
                    } 
                     else
                    {
                        if (isset($response_data['status']) && $response_data['status'] === 99) {

                    $statusCode = 99;
                    $responseId ='';


     $message .= $response_data['message'] ?? "No error message provided.";
    //echo "Error Message: " . $errorMessage . PHP_EOL;
} 
                        
                    }
                    
                }


               /* if (isset($response_data['code']) && $response_data['code'] === 400) {

                    $statusCode = 400;
                    $responseId ='';


   
    if (isset($response_data['errors']) && is_array($response_data['errors'])) {
      
        foreach ($response_data['errors'] as $field => $issues) {
            foreach ($issues as $issue) {
            
                $message .= "Error: " . implode(", ", $issue).'( credibly )';
            }
        }
    }
}*/


    if (isset($response_data['code']) && $response_data['code'] === 400) {

                                      $statusCode = 400;
                    $responseId ='';

// Check if the application_info key exists
if (isset($response_data['errors']['application_info'])) {
    echo "Application Info:\n";
    foreach ($response_data['errors']['application_info'] as $key => $values) {
        echo "Key: $key\n";
        foreach ($values as $value) {
            echo "Value: $value\n";

                $message .= "Error: ".$key.' '.$value.'( credibly )';

        }
    }
} else {
    echo "No application_info found.";
}

}

// Check if 'status' is 3 and 'declineReasons' exists
if (isset($response_data['status']) && $response_data['status'] == 3 && !empty($response_data['declineReasons'])) {
    foreach ($response_data['declineReasons'] as $reason) {
       // echo $reason . "<br>";
        $message .= $reason . "<br>";
    }
} else {
    echo "No decline reasons found or status is not 3.";
}


if (isset($response_data['message'])) {
    $statusCode = '400';
    $message .= "<p class='error'>Error: " . htmlspecialchars($response_data['message']) . "</p>";
}


$clientIp = request()->ip();
                            $userAgent = request()->header('User-Agent');
                ApiLog::create([
                    'endpoint' => $url,
                    'client_id' => $this->clientId,
                    'lender_id' => $lenderId,
                    'lead_id' => $crmLeadId,
                    'request_data' => json_encode($data),
                    'response_data' => $response,
                    'status_code' => $statusCode,
                    'request_ip' => $clientIp,
                    'user_agent' => $userAgent,
                    'businessID' => $responseId,
                    'created_at' => Carbon::now(),
                ]);
                

                //$businessID = $response_data['businessID'] ?? $business;

                $Notification = new Notification();
                $Notification->setConnection("mysql_{$this->clientId}");
                $Notification->user_id = $userId;
                $Notification->lead_id = $crmLeadId;
                $Notification->message = $message;
                $Notification->type = '2';
                $Notification->saveOrFail();

                $statusCode = $statusCode;//curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $clientIp = request()->ip();
                $userAgent = request()->header('User-Agent');


echo "<pre>";print_r($data);

$this->sendEmail($message,$userId,$crmLeadId,$this->clientId,'Credibly');

}



private function foxPartner($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId)
{

    //$this->sendEmail("hello",$userId,$crmLeadId,$this->clientId,'Fox Partner');die;

    $SystemSetting = SystemSetting::on("mysql_{$this->clientId}")->get()->first();;
    if($SystemSetting)
    {
        $rootPath = $SystemSetting->company_domain.'uploads/';
    }
    else
    {
        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
    }

    if (app()->environment() == "local") 
    {
        $rootPath = 'C:\xampp\htdocs\subscription_signup\frontend_beta\public\uploads/';
    }

    $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
    $fileNames = [];

    foreach ($document_lists as $key => $document) 
    {
        $filePaths[$key]['name'] = $document->file_name;
        $filePaths[$key]['base64'] = rtrim(strtr(base64_encode($rootPath.$document->file_name), '+/', '-_'), '=');
    }

    //echo "<pre>";print_r($filePaths);die;

    foreach ($arrLabels as $key => $arrLabel) 
    {
        foreach ($arrLabel as $originalKey => $value) 
        {
            $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
            if ($objLabelFound) 
            {
                $crm_label_id = $objLabelFound->id;
                $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id',$crm_label_id)->whereNotNull('fox_partner_label')->first();

                if ($label) 
                {
                    $fox_partner_label = $label->fox_partner_label;
                    if (isset($updatedArray[$fox_partner_label])) 
                    {
                        $updatedArrayFoxPartner[$fox_partner_label] .= ' ' . $value;
                    } 
                    else
                    {
                        $updatedArrayFoxPartner[$fox_partner_label] = $value;
                    }
                }
            }
        }
    }

    //echo "<pre>";print_r($updatedArrayFoxPartner);die;


    $data_request = 
    [
        "merchant" => [
        "merchantId" => $crmLeadId,
        "legalName" =>$updatedArrayFoxPartner['business.legalName'],
        "ein" =>preg_replace('/\D/', '', $updatedArrayFoxPartner['business.ein']),
        "dba" =>$updatedArrayFoxPartner['business.dba'] ?? $updatedArrayFoxPartner['business.legalName'],
        "businessStartDate" => $updatedArrayFoxPartner['business.businessStartDate'],
        "businessAddress" => [
            "address" => $updatedArrayFoxPartner['business.address'],
            "city" => $updatedArrayFoxPartner['business.city'],
            "state" => $updatedArrayFoxPartner['business.state']
        ],
        
        "owners" => [
            [
                "firstName" => $updatedArrayFoxPartner['owners.firstName'],
                "lastName" => $updatedArrayFoxPartner['owners.lastName'],
                "email" => $updatedArrayFoxPartner['owners.email'],
                "dob" => $updatedArrayFoxPartner['owners.dob'],
                "ssn" => preg_replace('/\D/', '', $updatedArrayFoxPartner['owners.ssn']),
                "address" => [
                    "address" => $updatedArrayFoxPartner['owners.address'],
                    "city" => $updatedArrayFoxPartner['owners.city'],
                    "state" => $updatedArrayFoxPartner['owners.state']
                ]
            ]
        ]
    ],

    [
        "documents" =>$filePaths
    ],

    "salesRepEmailAddress" => $emailLender->sales_rep_email,
    "alertEmailAddresses" => [
        "admin@example.com",
        "alerts@example.com"
    ]
];



    $username = $emailLender->username;
    $password = $emailLender->password;



    $ch = curl_init();
    //curl_setopt($ch, CURLOPT_URL, 'https://staging.identity.webfund.io/connect/token');
    curl_setopt($ch, CURLOPT_URL, 'https://identity.funderone.io/connect/token');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
    ]);

    curl_setopt($ch, CURLOPT_POSTFIELDS,'grant_type=client_credentials&client_id='.$username.'&client_secret='.$password.'');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    //echo $response->access_token;die;

    $data = json_decode($response, true);

    if (isset($data['access_token'])) {
        $accessToken = $data['access_token'];
    } else {
        echo "Access token not found.";
    }

   // echo $accessToken;die;


    curl_close($ch);
     $url = "{$emailLender->url}submissions";  // Create API endpoint
    //$token = $emailLender->api_key;

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data_request),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$accessToken,
            'Content-Type: application/json'
        ],
    ]);

    $response_data1 = curl_exec($curl);
    curl_close($curl);


    $response_data = json_decode($response_data1, true);
    $submissionId = $response_data['submissionId'] ?? null;
    $message = 'Lender <b>'.$lenderName. ' :</b>';


    if ($submissionId !== null) {
        $statusCode = '200';
        $message .= 'The Application has been submitted successfully'.' ( fox partner )';
    } else {
        $statusCode = '401';
        $submissionId ='';
        $errors = $response_data['errors'] ?? null;

        if (!empty($errors)) {
            foreach ($errors as $field => $messages) {
                foreach ($messages as $messageError) {
                    $message .= "Field: $field - Error: $messageError\n";
                }
            }
        }
    }

    //echo $message;die;

    $businessID = $submissionId;

    $statusCode = $statusCode;
    $clientIp = request()->ip();
    $userAgent = request()->header('User-Agent');

    ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => json_encode($data_request),
        'response_data' => $response_data1,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

    $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = $message;
    $Notification->type = '2';
    $Notification->saveOrFail();


    echo "<pre>";print_r($response_data1);

    $this->sendEmail($message,$userId,$crmLeadId,$this->clientId,'Fox Partner');

}

private function bittyAdvance($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId){
    Log::info('reached function bitty');
    $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
    Log::info('reached function document_lists',['document_lists'=>$document_lists]);
    $CrmLenderApis = CrmLenderApis::on("mysql_{$this->clientId}")->where('crm_lender_id', $lenderId)->first();
    Log::info('reached function bitty advance key',['CrmLenderApis'=>$CrmLenderApis]);
    $fileNames = [];

    if (app()->environment() == "local") 
    {
        $rootPath = 'C:\xampp\htdocs\crm_cafmotel\frontend_beta\public\uploads/';
        Log::info('reached function rootpath',['rootpath'=>$rootPath]);
    }
    else
    {
        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
    }



    foreach ($document_lists as $key => $document) 
    {
        $filePaths[$key]['name'] = $document->document_name;
        $filePaths[$key]['url'] = $rootPath.$document->file_name;
        $filePaths[$key]['type'] = $document->document_type;

    }



    Log::info('reached function filepath',['filePaths'=>$filePaths]);

//echo "<pre>";print_r($filePaths);die;
foreach ($arrLabels as $key => $arrLabel) 
{
foreach ($arrLabel as $originalKey => $value) 
{
    $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
    Log::info('reached function objLabelFound',['objLabelFound'=>$objLabelFound]);

    if ($objLabelFound) 
    {
        $crm_label_id = $objLabelFound->id;
        $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id', $crm_label_id)->whereNotNull('bittyadvance_label')->first();
        Log::info('reached function label',['label'=>$label]);

        if ($label) 
        {
            $bitty_advance_label = $label->bittyadvance_label;
            if (isset($updatedArray[$bitty_advance_label])) 
            {
                $updatedArrayBitty[$bitty_advance_label] .= ' ' . $value;
            } 
            else
            {
                $updatedArrayBitty[$bitty_advance_label] = $value;
            }
            Log::info('reached function bitty advance label',['updatedArrayBitty'=>$updatedArrayBitty[$bitty_advance_label]]);

        }
    }
}
}

$key = $emailLender->api_key;
$Fcs = Fcs::on("mysql_{$this->clientId}")->where('lead_id',$crmLeadId)->get();
Log::info('all added Fcs data', ['Fcs' => $Fcs]);

// Determine the last added month and calculate recent negative days
$recentNegativeDays = 0;
if ($Fcs->isNotEmpty()) {
    $lastMonth = $Fcs->last();
    Log::info('Last added Fcs data', ['lastMonth' => $lastMonth]);
    
    // Assuming a column 'negative_days' exists for calculation
    $recentNegativeDays = $lastMonth->negatives ?? 0; // Adjust based on your business logic
    Log::info('Last added Fcs negative', ['recentNegativeDays' => $recentNegativeDays]);
    $bank_id=$lastMonth->bank_id;
    $recentdeposit3 = $lastMonth->deposits ?? 0; // Adjust based on your business logic

}
$FcsLenderList = FcsLenderList::on("mysql_{$this->clientId}")->where('lead_id',$crmLeadId)->where('bank_id',$bank_id)->get();
$rowCount = FcsLenderList::on("mysql_{$this->clientId}")
    ->where('lead_id', $crmLeadId)
    ->where('bank_id', $bank_id)
    ->count();
    Log::info('reached rowcount',['rowCount'=>$rowCount]);
// Log::info('reached  bitty crmLead',['crmLead'=>$crmLead]);

// Define the request payload
$data = [
    "apikey" => $key,
    "development" => "1",
    "leadid" => $crmLeadId,
    "legal_name" => $updatedArrayBitty['business.legal_name'],
    "address" => $updatedArrayBitty['business.address'],
    "city" => $updatedArrayBitty['business.city'],
    "state" => $updatedArrayBitty['business.state'],
    "zip" =>  $updatedArrayBitty['business.zip'],
    "ein" => preg_replace('/\D/', '', $updatedArrayBitty['business.ein']),
    "start_date" => $updatedArrayBitty['business.start_date'],
    "owners" => [
        [
            "first_name" => $updatedArrayBitty['owners.first_name'],
            "last_name" =>$updatedArrayBitty['owners.last_name'],
            "address" => $updatedArrayBitty['owners.address'],
            "city" => $updatedArrayBitty['owners.city'],
            "state" => $updatedArrayBitty['owners.state'],
            "zip" => $updatedArrayBitty['owners.zip'],
            "email" => $updatedArrayBitty['owners.email'],
            "cell_phone" => $updatedArrayBitty['owners.cell_phone'],
            "dob" => $updatedArrayBitty['owners.dob'],
            "ssn" => preg_replace('/\D/', '', $updatedArrayBitty['owners.ssn']),
        ],
    ],
    "requested_amount" => $updatedArrayBitty['requested_amount'],
    "bankruptcy_current" =>0, //($updatedArrayBitty['bankruptcy'] === 'No') ? "1" : "0",
    "advance_default" =>0, //($updatedArrayBitty['advance_default'] === 'No') ? "1" : "0",
    "recent_negative_days" => $recentNegativeDays,
    "advance_current" => $rowCount,
];

//echo "<pre>";print_r($data);die;
// Add lender and payment details
foreach ($FcsLenderList as $index => $row) {
    $providerKey = "advance_provider" . ($index + 1);
    $paymentKey = "advance_payment" . ($index + 1);

    // Add to $data using the dynamically created keys
    $data[$providerKey] = $row->lender_name;
    $data[$paymentKey] = $row->weekly;
}
// Initialize deposits
$deposits = [
    "bank_deposits1" => null, // 3 months prior (3rd row)
    "bank_deposits2" => null, // 2 months prior (2nd row)
    "bank_deposits3" => null, // most recent (1st row)
];

// Skip the first record (assuming it's the one with only the bank_id and null values)
$filteredFcs = $Fcs->skip(1); // Skips the first row

// Loop through the remaining rows and assign deposits
foreach ($filteredFcs as $index => $entry) {
    if ($index == 0) {
        // The first row of the filtered set is the most recent deposit (bank_deposits3)
        $deposits['bank_deposits3'] = $entry->deposit;
    } elseif ($index == 1) {
        // The second row of the filtered set is the second most recent deposit (bank_deposits2)
        $deposits['bank_deposits2'] = $entry->deposit;
    } elseif ($index == 2) {
        // The third row of the filtered set is the third most recent deposit (bank_deposits1)
        $deposits['bank_deposits1'] = $entry->deposit;
    }

    // Stop after we've processed the first 3 rows
    if ($index >= 2) {
        break;
    }
}

// Ensure default values are set if deposits are missing
$deposits['bank_deposits1'] = $deposits['bank_deposits1'] ?? "0.00";
$deposits['bank_deposits2'] = $deposits['bank_deposits2'] ?? "0.00";
$deposits['bank_deposits3'] = $deposits['bank_deposits3'] ?? "0.00";
// Initialize variables to store total revenue and row count
$totalRevenue = 0;
$rowCount = 0;

// Loop through the Fcs records starting from the second row (skipping the first row)
foreach ($Fcs->skip(1) as $entry) {  // skip(1) skips the first row
    $totalRevenue += $entry->revenue ?? 0; // Sum up the revenue, handling null values
    $rowCount++; // Count the rows
}

// Calculate the average revenue
$averageRevenue = $rowCount > 0 ? $totalRevenue / $rowCount : 0; // Avoid division by zero

// Output the total revenue and average revenue
Log::info('Total Revenue (excluding first row): ' . $totalRevenue);
Log::info('Average Revenue (excluding first row): ' . $averageRevenue);
// Add other fields
$data += [
    "advance_freq1" => "2",
    "advance_freq2" => "2",
    "bank_deposits1" => $deposits['bank_deposits1'],
    "bank_deposits2" => $deposits['bank_deposits2'],
    "bank_deposits3" => $deposits['bank_deposits3'],
    "average_revenue" => $averageRevenue,
    "files" => [
        [
            "file" => base64_encode(file_get_contents("C:/Users/Abhishek/Downloads/Steve.pdf")),
            "type" => "1",
        ],
    ],
];


//echo "<pre>";print_r($data);die;

Log::info('reached function bitty advance data',['data'=>$data]);
$url = 'https://dev.bittyadvance.com/api/submit';
// Define the headers
$headers = [
    'Accept: application/json',
    'Content-Type: application/json',
];
// Initialize cURL
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

// Execute the request
$response = curl_exec($ch);
Log::info('response get',['response'=>$response]);
echo "<pre>";print_r($response);die;

Log::info('Request Data:', ['url' => $url, 'data' => $data]);
Log::info('Response Received:', ['response' => $response]);

if ($response === false) {
    Log::error('cURL Error:', ['error' => curl_error($ch)]);
}

// Decode the response
$decodedResponse = json_decode($response, true);

if (isset($decodedResponse['success']) && !$decodedResponse['success']) {
    Log::error('Validation Error Response:', $decodedResponse);
} else {
    Log::info('Successful Response:', $decodedResponse);
}

echo "<pre>";
print_r($decodedResponse);
// Close the cURL session
curl_close($ch);
$response_data = json_decode($response, true);
$submissionId = $response_data['id'] ?? null;
$message = 'Lender <b>'.$lenderName. ' :</b>';


if ($submissionId !== null) {
    $statusCode = '200';
    $message = 'Lender <b>'.$lenderName. '</b> :The Application has been submitted successfully'.' ( BittyAdvance )';
} else {

    $statusCode = '401';
    $submissionId ='';
    $errors = json_decode($response_data['error'], true);

    //echo "<pre>";print_r($errors);die;

    if (!empty($errors)) {
        foreach ($errors as $error) {

                $message .= $error['message'];

   
}
    }
}

/// echo $message;die;

$businessID = $submissionId;


 $statusCode = $statusCode;
$clientIp = request()->ip();
$userAgent = request()->header('User-Agent');

ApiLog::create([
    'endpoint' => $url,
    'client_id' => $this->clientId,
    'lender_id' => $lenderId,
    'lead_id' => $crmLeadId,
    'request_data' => json_encode($data),
    'response_data' => $response_data,
    'status_code' => $statusCode,
    'request_ip' => $clientIp,
    'user_agent' => $userAgent,
    'businessID' => $businessID,
    'created_at' => Carbon::now(),
]);

$Notification = new Notification();
$Notification->setConnection("mysql_{$this->clientId}");
$Notification->user_id = $userId;
$Notification->lead_id = $crmLeadId;
$Notification->message = $message;
$Notification->type = '2';
$Notification->saveOrFail();



}



private function lendiniApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId)
{
    $documentTypes = [ "Application", "Bank Statement", "Check", "Drivers License", "Identification", "Tax Document", "Tax Return" ];


    $SystemSetting = SystemSetting::on("mysql_{$this->clientId}")->get()->first();;
    if($SystemSetting)
    {
        $rootPath = $SystemSetting->company_domain.'uploads/';
    }
    else
    {
        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
    }

    if (app()->environment() == "local") 
    {
        $rootPath = 'C:\xampp\htdocs\subscription_signup\frontend_beta\public\uploads/';
    }

    $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
    $fileNames = [];

    //echo $rootPath;die;

    foreach ($document_lists as $key => $document) 
    {
        $inputDocumentType = $document->document_type; 

        $normalizedInput = $this->normalizeInput($inputDocumentType);

        $closestMatch = $this->findClosestMatch($normalizedInput, $documentTypes);

        $finalDocumentType = $closestMatch ?? "Application";

        // Output results
        /*echo "Original Input: $inputDocumentType\n";
        echo "Normalized Input: $normalizedInput\n";
        echo "Matched Type: $finalDocumentType\n";die;*/

        if($document->document_type == 'signature_application')
        {
            continue;
        }
        else
        {
            $filePaths[$key]['Type'] = $finalDocumentType;
            $filePaths[$key]['Name'] = $document->file_name;
            $filePaths[$key]['ContentType'] = "application/pdf";
            $filePaths[$key]['Body'] = rtrim(strtr(base64_encode($rootPath.$document->file_name), '+/', '-_'), '=');

        }



        

    }

    $filePaths = array_values($filePaths);

  //echo "<pre>";print_r($filePaths);die;

    foreach ($arrLabels as $key => $arrLabel) 
    {
        foreach ($arrLabel as $originalKey => $value) 
        {
            $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
            if ($objLabelFound) 
            {
                $crm_label_id = $objLabelFound->id;
                $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id',$crm_label_id)->whereNotNull('lendini_label')->first();

                if ($label) 
                {
                    $lendini_label = $label->lendini_label;
                    if (isset($updatedArray[$lendini_label])) 
                    {
                        $updatedArrayLendini[$lendini_label] .= ' ' . $value;
                    } 
                    else
                    {
                        $updatedArrayLendini[$lendini_label] = $value;
                    }
                }
            }
        }
    }

    //echo "<pre>";print_r($updatedArrayLendini);die;


    $data_request = [
    "companyLegalName" => $updatedArrayLendini['companyLegalName'],
    "companyEIN" => preg_replace('/\D/', '', $updatedArrayLendini['companyEIN']),
    "companyInceptionDate" => $updatedArrayLendini['companyInceptionDate'],
    "companyStreet" => $updatedArrayLendini['companyStreet'],
    "companyCity" => $updatedArrayLendini['companyCity'],
    "companyState" => $updatedArrayLendini['companyState'],
    "companyPostalCode" => $updatedArrayLendini['companyPostalCode'],
    "companyPhone" => $updatedArrayLendini['companyPhone'],
    "ownerFirstName" => $updatedArrayLendini['ownerFirstName'],
    "ownerLastName" => $updatedArrayLendini['ownerLastName'],
    "ownerStreet" => $updatedArrayLendini['ownerStreet'],
    "ownerCity" => $updatedArrayLendini['ownerCity'],
    "ownerState" => $updatedArrayLendini['ownerState'],
    "ownerPostalCode" => $updatedArrayLendini['ownerPostalCode'],
    "ownerBirthdate" => $updatedArrayLendini['ownerBirthdate'],
    "ownerSsn" => preg_replace('/\D/', '', $updatedArrayLendini['ownerSsn']),
    "ownerEmail" => $updatedArrayLendini['ownerEmail'],
    "ownerMobilePhone" => $updatedArrayLendini['ownerMobilePhone'],
    "ownerPercentOwnership" => $updatedArrayLendini['ownerPercentOwnership'],
    "documents" => 
        $filePaths
    
];

   // echo "<pre>";print_r($data_request);die;



    
    $url = "{$emailLender->url}postNewDeal";  // Create API endpoint
    $token = $emailLender->api_key;

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data_request),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'token-v: '.$token,
            'development:true'
        ],
    ]);

    $response_data1 = curl_exec($curl);

    //echo "<pre>";print_r($response_data1);die;
    curl_close($curl);


    $response_data = json_decode($response_data1, true);
    $submissionId = $response_data['id'] ?? null;
    $message = 'Lender <b>'.$lenderName. ' :</b>';


    if ($submissionId !== null) {
        $statusCode = '200';
        $message = 'Lender <b>'.$lenderName. '</b> :The Application has been submitted successfully'.' ( lendini )';
    } else {

        $statusCode = '401';
        $submissionId ='';
        $errors = json_decode($response_data['error'], true);

        //echo "<pre>";print_r($errors);die;

        if (!empty($errors)) {
            foreach ($errors as $error) {

                    $message .= $error['message'];

       
    }
        }
    }

   /// echo $message;die;

    $businessID = $submissionId;


     $statusCode = $statusCode;
    $clientIp = request()->ip();
    $userAgent = request()->header('User-Agent');

    ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => json_encode($data_request),
        'response_data' => $response_data1,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

    $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = $message;
    $Notification->type = '2';
    $Notification->saveOrFail();

   

    echo "<pre>ss";print_r($response_data1);

$this->sendEmail($message,$userId,$crmLeadId,$this->clientId,'lendini');

}



private function specialityApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId)
{


$documentTypes = [
    "Signed Application",
    "Processing Statements",
    "Bank Statements",
    "Drivers License",
    "Voided Check",
    "Business Lease/ Business Mortgage",
    "Business License",
    "Balance Letter",
    "Financials",
    "Trade Reference",
    "Proof of Majority Ownership",
    "Misc License",
    "Signed Contract",
    "UCCs",
    "External Signed Doc",
    "External Unsigned Doc",
    "Calculator",
    "Other"
];

    $SystemSetting = SystemSetting::on("mysql_{$this->clientId}")->get()->first();;
    if($SystemSetting)
    {
        $rootPath = $SystemSetting->company_domain.'uploads/';
    }
    else
    {
        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
    }
    if (app()->environment() == "local") 
    {
        $rootPath = 'C:\xampp\htdocs\subscription_signup\frontend_beta\public\uploads/';
    }

    $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
    $fileNames = [];

    foreach ($document_lists as $key => $document) 
    {
        $inputDocumentType = $document->document_type; 

        $normalizedInput = $this->normalizeInput($inputDocumentType);
        $closestMatch = $this->findClosestMatch($normalizedInput, $documentTypes);

        $finalDocumentType = $closestMatch ?? "Other";

        $fileNameWithoutExtension = preg_replace('/\.pdf$/i', '', $document->file_name);

        $filePaths[$key]['category'] = $finalDocumentType;
        $filePaths[$key]['name'] = $fileNameWithoutExtension;
        $filePaths[$key]['filename'] = $document->file_name;
        $filePaths[$key]['content'] = rtrim(strtr(base64_encode($rootPath.$document->file_name), '+/', '-_'), '=');
    }

    foreach ($arrLabels as $key => $arrLabel) 
    {
        foreach ($arrLabel as $originalKey => $value) 
        {
            $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
            if ($objLabelFound) 
            {
                $crm_label_id = $objLabelFound->id;
                $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id',$crm_label_id)->whereNotNull('specialty_label')->first();

                if ($label) 
                {
                    $specialty_label = $label->specialty_label;
                    if (isset($updatedArray[$specialty_label])) 
                    {
                        $updatedArraySpeciality[$specialty_label] .= ' ' . $value;
                    } 
                    else
                    {
                        $updatedArraySpeciality[$specialty_label] = $value;
                    }
                }
            }
        }
    }

    $data_request = 
    '{
        "business": 
        {
            "name": "'.$updatedArraySpeciality['business.name'].'",
            "dba": "'.$updatedArraySpeciality['business.name'].'",
            "address": "'.$updatedArraySpeciality['business.address'].'",
            "city": "'.$updatedArraySpeciality['business.city'].'",
            "state": "'.$updatedArraySpeciality['business.state'].'",
            "zip": "'.$updatedArraySpeciality['business.zip'].'",
            "telephone": "'.$updatedArraySpeciality['business.telephone'].'",
            "fein": "'.preg_replace('/\D/', '', $updatedArraySpeciality['business.fein']).'",
            "amountRequested": "'.$updatedArraySpeciality['business.amountRequested'].'",
            "startDate": "'.$updatedArraySpeciality['business.startDate'].'",
            "owners": 
            [{
                "firstName": "",
                "lastName": "'.$updatedArraySpeciality['owner.lastName'].'",
                "socialSecurityNumber": "'.preg_replace('/\D/', '', $updatedArraySpeciality['owner.socialSecurityNumber']).'",
                "dateOfBirth": "'.$updatedArraySpeciality['owner.dateOfBirth'].'",
                "address": "'.$updatedArraySpeciality['owner.address'].'",
                "city": "'.$updatedArraySpeciality['owner.city'].'",
                "state": "'.$updatedArraySpeciality['owner.state'].'",
                "zip": "'.$updatedArraySpeciality['owner.zip'].'",
                "emailAddress": "'.$updatedArraySpeciality['owner.emailAddress'].'",
                "ownershipPercentage": "'.$updatedArraySpeciality['owner.ownershipPercentage'].'",
                "mobilePhone": "'.$updatedArraySpeciality['owner.mobilePhone'].'"
            }],
            "documents": 
            '.json_encode($filePaths).'
        }
    }';


    $url = "{$emailLender->url}deal-submission";  // Create API endpoint
    $token = $emailLender->api_key;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $data_request,
        CURLOPT_HTTPHEADER => [
            'x-api-key: '.$token
        ],
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $response_data = json_decode($response, true);
    $submissionId = $response_data['id'] ?? null;
    $message = 'Lender <b>'.$lenderName. ' :</b>';

    if ($submissionId !== null) {
        $statusCode = '200';
        $message = 'Lender <b>'.$lenderName. '</b> :The Application has been submitted successfully'.' ( speciality )';
    } 
    else 
    {
        $statusCode = '401';
        $submissionId ='';
        $message .= $response_data['message'].' ( speciality )';
    }

    $businessID = $submissionId;
    $statusCode = $statusCode;
    $clientIp = request()->ip();
    $userAgent = request()->header('User-Agent');


    ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => $data_request,
        'response_data' => $response,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

    $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = $message;
    $Notification->type = '2';
    $Notification->saveOrFail();
    echo "<pre>ss";print_r($response_data);

$this->sendEmail($message,$userId,$crmLeadId,$this->clientId,'Speciality');

}



private function forwardFinancingApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId)
{
    $SystemSetting = SystemSetting::on("mysql_{$this->clientId}")->get()->first();;
    if($SystemSetting)
    {
        $rootPath = $SystemSetting->company_domain.'uploads/';
    }
    else
    {
        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
    }
    if (app()->environment() == "local") 
    {
        $rootPath = 'C:\xampp\htdocs\subscription_signup\frontend_beta\public\uploads/';
    }

    $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
    $fileNames = [];

    

    foreach ($arrLabels as $key => $arrLabel) 
    {
        foreach ($arrLabel as $originalKey => $value) 
        {
            $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
            if ($objLabelFound) 
            {
                $crm_label_id = $objLabelFound->id;
                $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id',$crm_label_id)->whereNotNull('forward_financing_label')->first();

                if ($label) 
                {
                    $forward_financing_label = $label->forward_financing_label;
                    if (isset($updatedArray[$forward_financing_label])) 
                    {
                        $updatedArrayForward[$forward_financing_label] .= ' ' . $value;
                    } 
                    else
                    {
                        $updatedArrayForward[$forward_financing_label] = $value;
                    }
                }
            }
        }
    }

    //echo "<pre>";print_r($updatedArrayForward);die;

    $data_request = 
    '{
  "lead": {
    "contacts_attributes": [
      {
        "first_name": "'.$updatedArrayForward['owner.first_name'].'",
        "last_name": "'.$updatedArrayForward['owner.last_name'].'",
        "email": "'.$updatedArrayForward['owner.email'].'",
        "born_on": "'.$updatedArrayForward['owner.born_on'].'",
        "cell_phone": "'.$updatedArrayForward['owner.cell_phone'].'",
        "ssn": "'.preg_replace('/\D/', '', $updatedArrayForward['owner.ssn']).'",
        "current_address_attributes": {
          "street1": "'.$updatedArrayForward['owner.street1'].'",
          "city": "'.$updatedArrayForward['owner.city'].'",
          "state": "'.$updatedArrayForward['owner.state'].'",
          "zip": "'.$updatedArrayForward['owner.zip'].'"
        }
      }
    ],
    "account_attributes": {
      "name": "'.$updatedArrayForward['business.legal_name'].'",
      "legal_name": "'.$updatedArrayForward['business.legal_name'].'",
      "phone": "'.$updatedArrayForward['business.phone'].'",
      "fein": "'.preg_replace('/\D/', '', $updatedArrayForward['business.fein']).'",
      "current_address_attributes": {
        "street1": "'.$updatedArrayForward['business.street1'].'",
        "city": "'.$updatedArrayForward['business.city'].'",
        "state": "'.$updatedArrayForward['business.state'].'",
        "zip": "'.$updatedArrayForward['business.zip'].'"
      }
    },
    "application_attributes": {
      "owner_1_percent_ownership": "'.$updatedArrayForward['owner.owner_1_percent_ownership'].'"
    }
  }
}';

   // echo "<pre>";print_r($data_request);die;


     $url = "{$emailLender->url}lead"; // Create API endpoint
     $token = $emailLender->api_key;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $data_request,
        CURLOPT_HTTPHEADER => [
            'x-api-key: '.$token,
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($curl);

    //echo "<pre>";print_r($response);die;
    curl_close($curl);

    $response_data = json_decode($response, true);
    $submissionId = $response_data['id'] ?? null;


    $message = 'Lender <b>'.$lenderName. ' :</b>';

    if ($submissionId !== null) {
        $statusCode = '200';
        $message = 'Lender <b>'.$lenderName. '</b> :The Application has been submitted successfully'.' ( Forward Financing )';
    } 
    else 
    {
        $statusCode = '401';
        $submissionId ='';
        $message .= $response_data['message'].' ( Forward Financing )';
    }

    $businessID = $submissionId;
    $statusCode = $statusCode;
    $clientIp = request()->ip();
    $userAgent = request()->header('User-Agent');


    ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => $data_request,
        'response_data' => $response,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

    $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = $message;
    $Notification->type = '2';
    $Notification->saveOrFail();




      foreach ($document_lists as $key => $document) 
    {
        $filePaths[$key]['lead_id'] = $submissionId;
        $filePaths[$key]['filename'] = $document->file_name;
        $filePaths[$key]['attachment_url'] = $rootPath.$document->file_name;
    }


      foreach($filePaths as $paths)

    {

        $json['attachment_url'] = $paths['attachment_url'];
        $json['filename'] = $paths['filename'];
        $json['lead_id'] = $paths['lead_id'];



         $url = "{$emailLender->url}attachment"; // Create API endpoint
     $token = $emailLender->api_key;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($json),
        CURLOPT_HTTPHEADER => [
            'x-api-key: '.$token,
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($curl);

    //echo "<pre>";print_r($response);die;
    curl_close($curl);

    $response_data = json_decode($response, true);


     ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => json_encode($json),
        'response_data' => $response,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

   
        
    }

     $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = "Your attachment was received and is being processed (Forward Financing)";
    $Notification->type = '2';
    $Notification->saveOrFail();

    












    echo "<pre>ss";print_r($response_data);

$this->sendEmail($message,$userId,$crmLeadId,$this->clientId,'Forward Financing');

}




private function canCapitalApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId)
{

    // Define the array of valid document types
$documentTypes = [
    "Application",
    "Bank Statements",
    "Competitor Loan Information",
    "ID Verification",
    "Loan Agreement",
    "Month to Date Banks",
    "Other",
    "Proof of Ownership",
    "Refinance Agreement",
    "Tax Lien Statement",
    "Tax Return",
    "Third Party Release Authorization",
    "Voided Check"
];
    $SystemSetting = SystemSetting::on("mysql_{$this->clientId}")->get()->first();;
    if($SystemSetting)
    {
        //$rootPath = $SystemSetting->company_domain.'uploads/';

        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
    }
    else
    {
        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
    }
    if (app()->environment() == "local") 
    {
        $rootPath = 'C:\xampp\htdocs\subscription_signup\frontend_beta\public\uploads/';
    }

    $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
    $fileNames = [];

    

    foreach ($arrLabels as $key => $arrLabel) 
    {
        foreach ($arrLabel as $originalKey => $value) 
        {
            $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
            if ($objLabelFound) 
            {
                $crm_label_id = $objLabelFound->id;
                $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id',$crm_label_id)->whereNotNull('cancapital_label')->first();

                if ($label) 
                {
                    $cancapital_label = $label->cancapital_label;
                    if (isset($updatedArray[$cancapital_label])) 
                    {
                        $updatedArrayCancapital[$cancapital_label] .= ' ' . $value;
                    } 
                    else
                    {
                        $updatedArrayCancapital[$cancapital_label] = $value;
                    }
                }
            }
        }
    }

    //echo json_encode($updatedArrayCancapital);die;




    $username = $emailLender->username;
    $password = $emailLender->password;
    $client_secret = $emailLender->api_key;
    $client_id = $emailLender->client_id;
    $auth_url = $emailLender->auth_url;





    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $auth_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    curl_setopt($ch, CURLOPT_COOKIE, 'BrowserId=WAuknwQtEeydvVlkjweasiuu34wT9wikAQ; CookieConsentPolicy=0:0');
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=password&client_id='.$client_id.'&client_secret='.$client_secret.'&username='.$username.'&password='.$password);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$response = curl_exec($ch);

$data = json_decode($response, true);

//echo "<pre>";print_r($data);die;

// Extract the access_token
if (isset($data['access_token'])) {
    $accessToken = $data['access_token'];
    echo "Access Token: " . $accessToken;
} else {
    echo "Access token not found in the response.";
}

curl_close($ch);





// Endpoint URL
 $url = "{$emailLender->url}createapplication"; // Create API endpoint

// Authorization token
$authorization = 'OAuth '.$accessToken;


// JSON data to send in the request
$data = [
    "loanDetails" => [
        "loanAmount" => $updatedArrayCancapital['loanAmount']
    ],
    "partnerDetails" => [
        "partnerAPIKey" => $emailLender->partner_api_key,
        "partnerEmail" => $emailLender->sales_rep_email
    ],
    "accountDetails" => [
        "name" => $updatedArrayCancapital['name'],
        "phone" => $updatedArrayCancapital['phone'],
        "industry" => "Business Services",
        "taxId" => preg_replace('/\D/', '', $updatedArrayCancapital['taxId']),
        "dba" => $updatedArrayCancapital['name'],
        "businessStructureName" => "Corporation",
        "stateOfFormation" => $updatedArrayCancapital['billingState'],
        "bizStartDate" => $updatedArrayCancapital['bizStartDate'],
        "billingStreet" => $updatedArrayCancapital['billingStreet'],
        "billingCity" => $updatedArrayCancapital['billingCity'],
        "billingState" => $updatedArrayCancapital['billingState'],
        "billingPostalCode" => $updatedArrayCancapital['billingPostalCode'],
        "billingCountry" => "US"
    ],
    "contactDetails" => [
        "title" => "CEO",
        "firstName" => $updatedArrayCancapital['firstName'],
        "lastName" => $updatedArrayCancapital['lastName'],
        "email" => $updatedArrayCancapital['email'],
        "phone" => $updatedArrayCancapital['phone'],
        "birthDate" => $updatedArrayCancapital['birthDate'],
        "socialSecurityNumber" => preg_replace('/\D/', '', $updatedArrayCancapital['socialSecurityNumber']),
        "mailingStreet" => $updatedArrayCancapital['mailingStreet'],
        "mailingCity" => $updatedArrayCancapital['mailingCity'],
        "mailingState" => $updatedArrayCancapital['mailingState'],
        "mailingCountry" => "US",//$updatedArrayCancapital['mailingCountry'],
        "mailingPostalCode" => $updatedArrayCancapital['mailingPostalCode']
    ]
];

//echo json_encode($data);die;

// Initialize cURL session
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: $authorization",
    "Content-Type: text/plain",
    "Cookie: BrowserId=WAuknwQtEeydvVlkjweasiuu34wT9wikAQ; CookieConsentPolicy=0:0"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

// Execute the request
$response = curl_exec($ch);

//echo "<pre>";print_r($response);die;

// Check for errors
if (curl_errno($ch)) {
    echo 'cURL Error: ' . curl_error($ch);
} else {
    // Print the response
    echo 'Response: ' . $response;
}

// Close the cURL session
curl_close($ch);

   

    $response_data = json_decode($response, true);
    $submissionId = $response_data[0]['ContactDetails']['Id'] ?? null;

    $applicationName = $response_data[0]['ApplicationDetails']['Name'] ?? null; // Get ApplicationDetails Name


    $message = 'Lender <b>'.$lenderName. ' :</b>';

    if ($submissionId !== null) {
        $statusCode = '200';
        $message = 'Lender <b>'.$lenderName. '</b> :The Application has been submitted successfully'.' ( Can Capital )';
    } 
    else 
    {
        $statusCode = '401';
        $submissionId ='';
        $message .= $response_data[0]['message'].' ( Can Capital )';
    }

    $businessID = $submissionId;
    $statusCode = $statusCode;
    $clientIp = request()->ip();
    $userAgent = request()->header('User-Agent');


    ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => json_encode($data),
        'response_data' => $response,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

    $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = $message;
    $Notification->type = '2';
    $Notification->saveOrFail();

$this->sendEmail($message,$userId,$crmLeadId,$this->clientId,'Can Capital');



if(isset($applicationName))
{

      foreach ($document_lists as $key => $document) 
    {
        $filePaths[$key]['filename'] = $document->file_name;
        $filePaths[$key]['attachment_url'] = $rootPath.$document->file_name;
        $filePaths[$key]['document_type'] = $document->document_type;

    }


      foreach($filePaths as $paths)

    {



$inputDocumentType = $paths['document_type']; // Example input

// Input document type to check

// Normalize the input
$normalizedInput = $this->normalizeInput($inputDocumentType);

// Find the closest match
$closestMatch = $this->findClosestMatch($normalizedInput, $documentTypes);

// Store in appropriate variable
$finalDocumentType = $closestMatch ?? "Other";

// Output results
/*echo "Original Input: $inputDocumentType\n";
echo "Normalized Input: $normalizedInput\n";
echo "Matched Type: $finalDocumentType\n";die;*/

       

        $json['application'] = $applicationName;
    $json['partnerAPIKey'] = $emailLender->partner_api_key;
    $json['partnerEmail'] = $emailLender->sales_rep_email;
    $json['name'] = $paths['filename'];
    $json['documentType'] = $finalDocumentType; //$paths['document_type'];



         $url = "{$emailLender->url}uploaddocs"; // Create API endpoint
     
// Build the complete URL with query parameters
$urlWithParams = $url . '?' . http_build_query($json);

// Define headers
$headers = [
    "Authorization: $authorization", // Double quotes allow variable interpolation
    "Content-Type: application/pdf",
    "Cookie: BrowserId=WAuknwQtEeydvVlkjweasiuu34wT9wikAQ; CookieConsentPolicy=0:0",
];

$filePath = $paths['attachment_url'];

// Check if the file exists
if (!file_exists($filePath)) {
    die('File not found: ' . $filePath);
}

// Initialize cURL
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $urlWithParams);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filePath));

// Execute the cURL request
$response = curl_exec($ch);

// Check for cURL errors
if (curl_errno($ch)) {
    echo 'cURL error: ' . curl_error($ch);
} else {
    // Print the response
    echo 'Response: ' . $response;
}

// Close cURL
curl_close($ch);

    $response_data = json_decode($response, true);


     ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => json_encode($json),
        'response_data' => $response,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

    $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = "Your attachment was received and is being processed (Can Capital)";
    $Notification->type = '2';
    $Notification->saveOrFail();
        
    }


    // URL
$url = "{$emailLender->url}processapplication";

// Payload
$data = [
    "application" => $applicationName,//"APP-0000001548",
    "consentAccepted" => true,
    "partnerDetails" => [
        "partnerAPIKey" => $emailLender->partner_api_key,
        "partnerEmail" => $emailLender->sales_rep_email
    ]
];


// Initialize cURL session
$ch = curl_init($url);

// Set cURL options
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: $authorization",
    'Content-Type: text/plain',
    'Cookie: BrowserId=WAuknwQtEeydvVlkjweasiuu34wT9wikAQ; CookieConsentPolicy=0:0'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // Encode data as JSON

// Execute the request and fetch the response
$response = curl_exec($ch);

// Check for errors
if (curl_errno($ch)) {
    echo 'cURL error: ' . curl_error($ch);
} else {
    echo 'Response: ' . $response;
}

// Close cURL session
curl_close($ch);


 $response_data = json_decode($response, true);

        $statusCode = '200';



     ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => json_encode($data),
        'response_data' => $response,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

    $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = "consentAccepted (Can Capital)";
    $Notification->type = '2';
    $Notification->saveOrFail();
































}


    echo "<pre>ss";print_r($response_data);


}


// Function to find the closest match
private function normalizeInput($input)
{
    // Convert to lowercase and remove unwanted characters (like dates, hyphens, etc.)
    $normalized = strtolower($input);
    $normalized = preg_replace('/[^a-z\s]/', ' ', $normalized); // Remove non-alphabetic characters
    $normalized = preg_replace('/\s+/', ' ', $normalized); // Replace multiple spaces with a single space
    return trim($normalized);
}

// Function to find the closest match, prioritizing partial matches
private function findClosestMatch($input, $documentTypes)
{
    $closest = null;
    $shortest = -1; // Minimum Levenshtein distance
    $inputWords = explode(' ', $input); // Break input into words for partial matching
    
    foreach ($documentTypes as $type) {
        $normalizedType = strtolower($type); // Normalize the document type
        
        // Check if any word in the input matches part of the document type
        foreach ($inputWords as $word) {
            if (strpos($normalizedType, $word) !== false) {
                return $type; // Prioritize partial match
            }
        }

        // Calculate Levenshtein distance for fallback
        $lev = levenshtein($input, $normalizedType);
        if ($lev === 0) {
            return $type; // Exact match
        }

        if ($lev < $shortest || $shortest === -1) {
            $closest = $type;
            $shortest = $lev;
        }
    }

    // Return the closest match or null if no matches
    return $shortest <= 5 ? $closest : null; // Threshold of 5 for similarity
}



private function biz2CreditApi($arrLabels,$emailLender,$lenderName,$userId,$crmLeadId,$lenderId)
{
    $SystemSetting = SystemSetting::on("mysql_{$this->clientId}")->get()->first();;
    if($SystemSetting)
    {
        //$rootPath = $SystemSetting->company_domain.'uploads/';
        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';

    }
    else
    {
        $rootPath = '/var/www/html/branch/frontend_beta/public/uploads/';
    }
    if (app()->environment() == "local") 
    {
        $rootPath = 'C:\xampp\htdocs\subscription_signup\frontend_beta\public\uploads/';
    }

    $document_lists = Documents::on("mysql_{$this->clientId}")->where('lead_id', $crmLeadId)->get();
    $fileNames = [];

    

    foreach ($arrLabels as $key => $arrLabel) 
    {
        foreach ($arrLabel as $originalKey => $value) 
        {
            $objLabelFound = CrmLabel::on("mysql_{$this->clientId}")->where('label_title_url', $originalKey)->first();
            if ($objLabelFound) 
            {
                $crm_label_id = $objLabelFound->id;
                $label = CrmLenderApiLabels::on("mysql_{$this->clientId}")->where('crm_label_id',$crm_label_id)->whereNotNull('forward_financing_label')->first();

                if ($label) 
                {
                    $forward_financing_label = $label->forward_financing_label;
                    if (isset($updatedArray[$forward_financing_label])) 
                    {
                        $updatedArrayForward[$forward_financing_label] .= ' ' . $value;
                    } 
                    else
                    {
                        $updatedArrayForward[$forward_financing_label] = $value;
                    }
                }
            }
        }
    }


     
    //echo "<pre>";print_r($updatedArrayForward);die;

    $data_request = [
    "product_type" => "termloan",
    "affiliate_lead_reference_id" => bin2hex(random_bytes(10)),
    "track_id" => 34238,
    "lead_id" => $crmLeadId,
    "business_info" => [
        "biz_legal_name" => "Test",
        "guarantor" => [
            [
                "business_name" => "Test Test",
            ]
        ],
        "dba" => "JoeyLand",
        "biz_phone" => "(999) 988-9966",
        "biz_tin" => "201014073",
        "biz_address" => [
            "address_line1" => "Street's 23",
            "city" => "Accord",
            "state" => "NY",
            "zipcode" => "11002",
            "country" => "United States"
        ],
        "ssn" => "511860485",
        "year_of_establishment" => "1997-12-01",
        
        
      
        "naics_code" => "448120",
        "is_state_corp" => false,
        "state_of_incorporation" => "WA",
        "other_funding_option" => 1,
        "role_in_company" => "Owner"
    ],
    "owner_info" => [
        [
            "email" => "joedavis@b2cdev.com",
            "phone" => "(999) 988-9988",
            "first_name" => "Joe",
            "last_name" => "Davis",
            "tin" => "646984298",
            "ownership_percentage" => 100,
            "date_of_birth" => "1990-01-01",
            "address" => [
                "address_line1" => "631 ADAMS",
                "city" => "SCRANTON",
                "state" => "PA",
                "zipcode" => "18510"
            ],
            "credit_consent" => 1,
            "is_corporate" => 1,
            "biz_legal_name" => "Jose Autos",

            "beneficiary" => [
                [
                    "biz_legal_name" => "",
                    "email" => "",
                    "phone" => "",
                    "tin" => "",
                    "ownership_percentage" => "",
                    "date_of_birth" => "",
                    "address" => [
                        "suit" => "",
                        "address_line1" => "",
                        "address_line2" => "",
                        "city" => "",
                        "state" => "",
                        "zipcode" => ""
                    ],
                    "personal_income" => "",
                    "personal_expence" => "",
                    "job_title" => "",
                    "is_primary" => true
                ]
            ]
            
        ]
    ],
    "callback_url" => ""
];

   // echo "<pre>";print_r($data);die;

$curl = curl_init();


curl_setopt_array($curl, [
    CURLOPT_URL => 'https://partner-integration-stage.b2cdev.com/api/v2/create-application',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => json_encode($data_request),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json'
    ],
]);

$response = curl_exec($curl);

if (curl_errno($curl)) {
    echo "cURL error: " . curl_error($curl);
} else {
    echo $response;
}

curl_close($curl);

$data = json_decode($response, true);

$submissionId = $data['data']['case_id'] ?? null;

$businessID = $submissionId;




// Decode JSON to PHP array

// Check if status is "error" and print the message
if (isset($data['status']) && $data['status'] === "error") {
    echo $data['data']['message'];

    $statusCode = '403';
        //$submissionId ='';
        $message .= $data['data']['message'].' ( Biz2Credit )';
}
else
if (isset($data['status']) && $data['status'] === "success") {
   // echo $data['data']['message'];
    //echo $data['data']['case_id'];

    $statusCode = '200';
    $message = 'Lender <b>'.$lenderName. '</b> :'.$data['data']['message'].' ( Biz2Credit )';

    $presignedUrl = $data['data']['presigned_url'] ?? '';

}

$clientIp = request()->ip();
    $userAgent = request()->header('User-Agent');

ApiLog::create([
        'endpoint' => $url,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => json_encode($data_request),
        'response_data' => $data,
        'status_code' => $statusCode,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

    $Notification = new Notification();
    $Notification->setConnection("mysql_{$this->clientId}");
    $Notification->user_id = $userId;
    $Notification->lead_id = $crmLeadId;
    $Notification->message = $message;
    $Notification->type = '2';
    $Notification->saveOrFail();

die;



   foreach ($document_lists as $key => $document) 
    {
        //$filePaths[$key]['lead_id'] = $submissionId;
        //$filePaths[$key]['filename'] = $document->file_name;
        $filePaths[$key]['attachment_url'] = $rootPath.$document->file_name;
    }


      foreach($filePaths as $paths)

    {

        $json['attachment_url'] = $paths['attachment_url'];
        

        $curl = curl_init();

// Read the file contents
$fileContents = file_get_contents($json['attachment_url']);

curl_setopt_array($curl, array(
    CURLOPT_URL => $presignedUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $fileContents, // Send the file contents
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/zip' // Set correct content type
    ),
));

$response = curl_exec($curl);
///echo $response;

if (curl_errno($curl)) {
    echo 'Curl error: ' . curl_error($curl1);
}

$httpCodesss = curl_getinfo($curl1, CURLINFO_HTTP_CODE); // Get the HTTP status code
curl_close($curl);





     ApiLog::create([
        'endpoint' => $presignedUrl,
        'client_id' => $this->clientId,
        'lender_id' => $lenderId,
        'lead_id' => $crmLeadId,
        'request_data' => $fileContents,
        'response_data' => $response,
        'status_code' => $httpCodesss,
        'request_ip' => $clientIp,
        'user_agent' => $userAgent,
        'businessID' => $businessID,
        'created_at' => Carbon::now(),
    ]);

   
        
    }

    if($httpCodesss == 200)
    {
        $Notification = new Notification();
        $Notification->setConnection("mysql_{$this->clientId}");
        $Notification->user_id = $userId;
        $Notification->lead_id = $crmLeadId;
        $Notification->message = "Your attachment was received and is being processed (Forward Financing)";
        $Notification->type = '2';
        $Notification->saveOrFail();
        
    }


    












    echo "<pre>ss";print_r($response_data);die;
}


function sendEmail($response,$userId,$leadId,$clientId,$lenderName)
{

    $name = User::findOrFail($userId);
    $sendEmailId = $name->email;
    if(empty($sendEmailId))
    {
        $sendEmailId = 'abhi4mca@gmail.com';
    }

    $smtp_setting = EmailSetting::on("mysql_$clientId")->where('mail_type','notification')->first();    


    $smtpSetting = new SmtpSetting;
    $smtpSetting->mail_driver = "SMTP";
    $smtpSetting->mail_host = $smtp_setting->mail_host;
    $smtpSetting->mail_port = $smtp_setting->mail_port;
    $smtpSetting->mail_username = $smtp_setting->mail_username;
    $smtpSetting->mail_password = $smtp_setting->mail_password;
    $smtpSetting->from_name = $smtp_setting->sender_name;
    $smtpSetting->from_email = $smtp_setting->sender_email;
    $smtpSetting->mail_encryption = $smtp_setting->mail_encryption;
    $from = [
        "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
        "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
    ];


    //echo $sendEmailId;die;
    $view = "emails.testmail";
    $data = (array)$response;
    $subject = 'API Response -'.$lenderName.' Lead Id - '.$leadId;

    $mailable = new SystemNotificationMail($from, $view, $subject, $data);
    $mailService = new MailService($clientId, $mailable, $smtpSetting);
    $mailService->sendEmail($sendEmailId);
    echo "mail send";
}






}
