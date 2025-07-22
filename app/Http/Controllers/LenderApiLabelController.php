<?php
namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Client\CrmPdfLabels;
use App\Model\Client\CrmLenderApiLabels;

use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use App\Model\Client\CrmLabel;
use App\Model\Client\CustomFieldLabelsValues;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use CURLFile;

class LenderApiLabelController extends Controller
{
    public function index(Request $request)
    {
        $crm_lender_labels = CrmLenderApiLabels::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("Crm Lender Apis lable List", $crm_lender_labels);
    }

    public function save(Request $request)
    {
        $data = json_decode(json_encode($request->data), true);
        $records = [];

        foreach ($data['label_id'] as $index => $labelId) {
            $records[] = [
                'crm_label_id' => $labelId,
                'ondeck_label' => $data['ondeck'][$index] ?? null,
                'credibly_label' => $data['credibly'][$index] ?? null,
                'bittyadvance_label' => $data['bittyadvance'][$index] ?? null,
                'fox_partner_label' => $data['fox_partner'][$index] ?? null,
                'lendini_label' => $data['lendini'][$index] ?? null,
                'specialty_label' => $data['specialty'][$index] ?? null,
                'forward_financing_label' => $data['forward_financing'][$index] ?? null,
                'cancapital_label' => $data['cancapital'][$index] ?? null,
                'rapid_label' => $data['rapid'][$index] ?? null,
                'biz2credit_label' => $data['biz2credit'][$index] ?? null,

            ];
        }

        CrmLenderApiLabels::on("mysql_" . $request->auth->parent_id)->truncate();
        $labels = CrmLenderApiLabels::on("mysql_" . $request->auth->parent_id)->insert($records);
        return $this->successResponse("Lender API Setting Update", [$labels]);
    }
}
