<?php
namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Client\CrmPdfLabels;
use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use App\Model\Client\CrmLabel;
use App\Models\Client\CrmLeadLabel;
use App\Model\Client\CustomFieldLabelsValues;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use CURLFile;

class PdfReaderController extends Controller
{

    public function index(Request $request)
    {
        $conn = "mysql_" . $request->auth->parent_id;
        $crm_pdf_labels = CrmPdfLabels::on($conn)->get();

        // Attach the mapped CRM label name + field_key for each mapping
        $result = $crm_pdf_labels->map(function ($item) use ($conn) {
            $row = $item->toArray();
            $row['crm_label_name'] = null;
            $row['crm_field_key'] = null;
            if ($item->crm_label_id) {
                $label = CrmLeadLabel::on($conn)->where('id', $item->crm_label_id)->first();
                if ($label) {
                    $row['crm_label_name'] = $label->label_name;
                    $row['crm_field_key'] = $label->field_key;
                }
            }
            return $row;
        });

        return $this->successResponse("Crm Pdf label List", $result->toArray());
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
        // ── 1. Resolve file path — multipart upload or legacy filename string ──
        $filePath = null;
        $tempFile = false;

        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $uploadDir = sys_get_temp_dir() . '/pdf_uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $uploadedFile->getClientOriginalName());
            $uploadedFile->move($uploadDir, $filename);
            $filePath = $uploadDir . '/' . $filename;
            $tempFile = true;
        } elseif ($request->has('file')) {
            $filename = $request->input('file');
            $filePath = env('FILE_UPLOAD_PATH') . $filename;
        }

        if (!$filePath || !file_exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'The file field is required.',
            ], 400);
        }

        Log::info('PDF upload filepath', ['filePath' => $filePath]);

        $conn = "mysql_" . $request->auth->parent_id;

        try {
            // ── 2. Send to external extraction service ──
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://sms-6qpk.onrender.com/pdf/extract?api-key=sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'x-api-key: sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p',
                'Content-Type: multipart/form-data',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file' => new CURLFile($filePath, 'application/pdf'),
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $curlError) {
                Log::error('PDF extraction curl error', ['error' => $curlError]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to connect to PDF extraction service.',
                ], 502);
            }

            $decoded = json_decode($response, true);
            if (!$decoded || !isset($decoded['response'])) {
                Log::error('PDF extraction invalid response', ['response' => $response]);
                return response()->json([
                    'success' => false,
                    'message' => 'PDF extraction returned an invalid response.',
                ], 502);
            }

            $array = $decoded['response'];

            // ── 3. Flatten nested JSON ──
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

            // ── 4. Build raw extracted map using leaf key names ──
            // e.g. "business_information.legal_corporate_name" → key "legal_corporate_name"
            $rawExtracted = [];
            foreach ($arrLabels as $arrLabel) {
                foreach ($arrLabel as $originalKey => $value) {
                    if ($value === null || $value === '') continue;
                    // Use the leaf portion after the last dot as the key
                    $leafKey = str_contains($originalKey, '.')
                        ? substr($originalKey, strrpos($originalKey, '.') + 1)
                        : $originalKey;
                    $rawExtracted[$leafKey] = $value;
                }
            }

            // ── 5. Try configured mapping via create_pdf_applications → crm_labels ──
            $mappedArray = [];
            foreach ($arrLabels as $arrLabel) {
                foreach ($arrLabel as $originalKey => $value) {
                    $objLabelFound = CrmPdfLabels::on($conn)->where('pdf_label', $originalKey)->first();
                    if ($objLabelFound && $objLabelFound->crm_label_id) {
                        // Look up in new crm_labels table (CrmLeadLabel) by ID
                        $label = CrmLeadLabel::on($conn)->where('id', $objLabelFound->crm_label_id)->first();
                        if ($label) {
                            $mappedArray[$label->field_key] = $value;
                        } else {
                            // Fallback to legacy crm_label table
                            $legacyLabel = CrmLabel::on($conn)->where('id', $objLabelFound->crm_label_id)->first();
                            if ($legacyLabel) {
                                $mappedArray[$legacyLabel->column_name] = $value;
                            }
                        }
                    }
                }
            }

            // ── 6. Merge: mapped values take priority, raw fills the rest ──
            $result = array_merge($rawExtracted, $mappedArray);

            return $this->successResponse("PDF data extracted", $result);

        } catch (\Exception $e) {
            Log::error('PDF upload error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the PDF.',
            ], 500);
        } finally {
            // ── 5. Clean up temp file ──
            if ($tempFile && $filePath && file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }
}
