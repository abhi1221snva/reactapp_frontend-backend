<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Model\Client\CrmOffer;
use App\Services\SystemChannelService;

class CrmOfferController extends Controller
{
    public function index(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $offers = CrmOffer::on($conn)->where('lead_id', (int)$id)->orderByDesc('created_at')->get();
        return $this->successResponse('Offers retrieved.', $offers->toArray());
    }
    public function store(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
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

        // Broadcast to #Lender system channel
        $lenderName = $data['lender_name'] ?? 'Unknown Lender';
        $amount = number_format((float) $data['offered_amount'], 2);
        $factorRate = $data['factor_rate'] ?? '?';
        $termDays = $data['term_days'] ?? '?';
        SystemChannelService::broadcast(
            (int) $request->auth->parent_id,
            'lender',
            "💰 New offer from {$lenderName}: \${$amount} at {$factorRate}x for {$termDays} days (Lead #{$id})",
            ['lead_id' => (int) $id, 'lender_id' => $data['lender_id'] ?? null, 'lender_name' => $lenderName, 'amount' => $data['offered_amount'], 'event' => 'offer']
        );

        return $this->successResponse('Offer created.', ['offer' => $offer], 201);
    }
    public function update(Request $request, $id, $oid)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $offer = CrmOffer::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$oid);
        $data = $request->only(['lender_id','lender_name','offered_amount','factor_rate','term_days','daily_payment','total_payback','stips_required','offer_expires_at','status','decline_reason','notes']);
        $offer->update(array_filter($data, fn($v) => !is_null($v)));
        return $this->successResponse('Offer updated.', ['offer' => $offer->fresh()]);
    }
    public function destroy(Request $request, $id, $oid)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $offer = CrmOffer::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$oid);
        $offer->delete();
        return $this->successResponse('Offer deleted.', []);
    }
    public function accept(Request $request, $id, $oid)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $offer = CrmOffer::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$oid);
        $offer->update(['status' => 'accepted']);
        // decline all other offers for this lead
        CrmOffer::on($conn)->where('lead_id', (int)$id)->where('id', '!=', (int)$oid)->whereIn('status', ['pending','received'])->update(['status' => 'declined']);

        // Broadcast to #Lender system channel
        $lenderName = $offer->lender_name ?? 'Unknown Lender';
        $amount = number_format((float) ($offer->offered_amount ?? 0), 2);
        SystemChannelService::broadcast(
            (int) $request->auth->parent_id,
            'lender',
            "✅ Offer from {$lenderName} accepted for Lead #{$id} — \${$amount}",
            ['lead_id' => (int) $id, 'lender_id' => $offer->lender_id ?? null, 'lender_name' => $lenderName, 'amount' => $offer->offered_amount, 'event' => 'offer_accepted']
        );

        return $this->successResponse('Offer accepted.', ['offer' => $offer->fresh()]);
    }
}
