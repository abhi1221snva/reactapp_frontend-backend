<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SendVoicemailToEmailController extends Controller
{
    public function index(Request $request)
    {


      


        $this->validate($request, ['email' => 'required','file_name_path' => 'required']);

        try
        {
            $email = $request->email;
            $file_name_path = $request->file_name_path;

            $url = explode("/", $file_name_path);
            $filename = end($url);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.sendgrid.com/v3/mail/send');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'authorization: Bearer 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',
                'Content-Type: application/json',
            ]);

            curl_setopt($ch, CURLOPT_POSTFIELDS, '{"personalizations": [{"to": [{"email": "'.$email.'"}]}],"from": {"email": "noreply@voiptella.com"},"subject":"Test Email","content": [{"type": "text/plain","value": "This is a test email with an attachment"}], "attachments": [{"content": "'.base64_encode($file_name_path).'", "type": "application/text", "filename": "'.$filename.'"}]}');

            $response = curl_exec($ch);
            curl_close($ch);



            return array('success' => 'true','message' => 'Email Send Successfully');
        }

        catch (\Throwable $exception) {
            return array('success' => 'false','message' => 'Email not Send Successfully');
        }
    }
}
