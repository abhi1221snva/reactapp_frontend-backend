<?php
namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Client\CrmPdfLabels;
use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use App\Model\Client\CrmLabel;
use App\Model\Client\CustomFieldLabelsValues;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use CURLFile;

class PdfReaderController extends Controller
{

    public function index(Request $request)
    {
        $crm_pdf_labels = CrmPdfLabels::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("Crm Pdf lable List", $crm_pdf_labels);
    }

    public function update(Request $request)
    {
        //return $request;
        foreach($request->data as $key => $reader)
        {
            $template = CrmPdfLabels::on("mysql_" . $request->auth->parent_id)->findOrFail($key);
            $template->crm_label_id = $reader;
            $template->saveOrFail();

        }
            return $this->successResponse("pdf reader Update", $template->toArray());

    }

    public function upload(Request $request)
    {     
        if (!$request->has('file')) 
        {
            return response()->json([
                'success' => false,
                'message' => 'The did file field is required.',
            ], 400);
        }

        if($request->has('file'))
        {
            //path of uploaded file
            $filename=$request->input('file');
            //$filename=$this->request->input('file');
            $filePath = env('FILE_UPLOAD_PATH').$filename;

            Log::info('reached filepath',['filePath'=>$filePath]);

            try
            {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://sms-6qpk.onrender.com/pdf/extract?api-key=sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: application/json',
                    'x-api-key: sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p',
                    'Content-Type: multipart/form-data',
                ]);

                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'file' => new CURLFile($filePath, 'application/pdf'),
                ]);

                $response = curl_exec($ch);

                $array = json_decode($response, true)['response'];

          //  return $this->successResponse("pdf reader Update",[$array]);


                $flattenJson = function ($data, $parentKey = '') use (&$flattenJson) {
                $items = [];
                foreach ($data as $key => $value) {
                    $newKey = $parentKey ? $parentKey . '.' . $key : $key;
                    if (is_array($value)) {
                        $items = array_merge($items, $flattenJson($value, $newKey));
                    } else {
                        $items[] = [$newKey => $value];
                    }
                }
                return $items;
            };

            $arrLabels = $flattenJson($array);


            foreach ($arrLabels as $key => $arrLabel) {
    foreach ($arrLabel as $originalKey => $value) {
        // Replace the original key with "id"

        //echo $storedKey = $originalKey;die;

                $objLabelFound = CrmPdfLabels::on("mysql_".$request->auth->parent_id)->where('pdf_label', $originalKey)->get()->first();
                if($objLabelFound)
                {
                $crm_label_id = $objLabelFound->crm_label_id;

                $label = CrmLabel::on("mysql_".$request->auth->parent_id)->where('id', $crm_label_id)->get()->first();
                if($label)
                {
        $updatedArray[$label->column_name] =  $value;
}
                }
    }
}
     

            return $this->successResponse("pdf reader Update", $updatedArray);

            curl_close($ch);
        } catch (Exception $e) {
            Log::error($e->getMessage()); // Corrected line
        } catch (InvalidArgumentException $e) {
            Log::error($e->getMessage()); // Corrected line
        }
        
    }
}
}
