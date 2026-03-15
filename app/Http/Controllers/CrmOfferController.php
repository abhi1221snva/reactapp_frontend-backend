<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Model\Client\CrmOffer;

class CrmOfferController extends Controller
{
    public function index(Request $request, $id)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        $offers = CrmOffer::on($conn)->where('lead_id', (int)$id)->orderByDesc('created_at')->get();
        return $this->successResponse('Offers retrieved.', $offers->toArray());
    }
    public function store(Request $request, $id)
    {
        $this->validate($request, [
            'offered_amount' => 'required|numeric|min:0',
            'factor_rate'    => 'required|numeric|min:1',
            'term_days'      => 'required|integer|min:1',
        ]);
        $conn = "mysql_{$request->auth->parent_id}";
        $data = $request->only(['lender_id','lender_name','offered_amount','factor_rate','term_days','daily_payment','total_payback','stips_required','offer_expires_at','status','notes']);
        $data['lead_id'] = (int)$id;
        $data['created_by'] = (int)$request->auth->id;
        if (!isset($data['status'])) $data['status'] = 'pending';
        // auto-compute totals if not provided
        if (empty($data['total_payback']) && isset($data['offered_amount'], $data['factor_rate'])) {
            $data['total_payback'] = round($data['offered_amount'] * $data['factor_rate'], 2);
        }
        if (empty($data['daily_payment']) && !empty($data['total_payback']) && !empty($data['term_days'])) {
            $data['daily_payment'] = round($data['total_payback'] / $data['term_days'], 2);
        }
        $offer = CrmOffer::on($conn)->create($data);
        return $this->successResponse('Offer created.', ['offer' => $offer], 201);
    }
    public function update(Request $request, $id, $oid)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        $offer = CrmOffer::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$oid);
        $data = $request->only(['lender_id','lender_name','offered_amount','factor_rate','term_days','daily_payment','total_payback','stips_required','offer_expires_at','status','decline_reason','notes']);
        $offer->update(array_filter($data, fn($v) => !is_null($v)));
        return $this->successResponse('Offer updated.', ['offer' => $offer->fresh()]);
    }
    public function destroy(Request $request, $id, $oid)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        $offer = CrmOffer::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$oid);
        $offer->delete();
        return $this->successResponse('Offer deleted.', []);
    }
    public function accept(Request $request, $id, $oid)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        $offer = CrmOffer::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$oid);
        $offer->update(['status' => 'accepted']);
        // decline all other offers for this lead
        CrmOffer::on($conn)->where('lead_id', (int)$id)->where('id', '!=', (int)$oid)->whereIn('status', ['pending','received'])->update(['status' => 'declined']);
        return $this->successResponse('Offer accepted.', ['offer' => $offer->fresh()]);
    }
}
