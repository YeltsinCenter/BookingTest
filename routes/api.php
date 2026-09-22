<?php

use App\Models\Referral;
use App\Models\ReferralEarning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
|
| Текущий мастер приходит в заголовке X-Master-Id и уже разложен
| в атрибуты запроса middleware'ом ResolveCurrentMaster:
|
|     $master = $request->attributes->get('current_master');
|
| Здесь нужно написать три роута — см. README.md.
|
*/

Route::get('/ping', fn () => ['ok' => true]);

Route::post('/referrals/attach', function (Request $request, \App\Services\Referral\ReferralService $service) {
    $me = $request->attributes->get('current_master');
    if (!$me) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $data = $request->validate([
        'code' => ['required', 'string'],
    ]);

    $referral = $service->registerReferral($me, $data['code']);

    if ($referral === null) {
        return response()->json(['error' => 'Invalid code or self-referral'], 422);
    }

    return response()->json([
        'id' => $referral->id,
        'status' => $referral->status,
        'referrer_master_id' => $referral->referrer_master_id,
    ]);
});

Route::get('/referrals/my', function (Request $request) {
    $me = $request->attributes->get('current_master');
    if (!$me) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $referrals = \App\Models\Referral::with('referredMaster')
        ->where('referrer_master_id', $me->id)
        ->get();

    $earnings = \App\Models\ReferralEarning::where('referral_master_id', $me->id)->get()->keyBy('referral_id');

    return $referrals->map(function (\App\Models\Referral $r) use ($earnings) {
        $earning = $earnings->get($r->id);

        return [
            'master_id' => $r->referred_master_id,
            'name' => $r->referredMaster?->name,
            'attached_at' => $r->created_at?->toIso8601String(),
            'counted' => $r->status === \App\Models\Referral::STATUS_REWARDED,
            'earned' => $earning ? (int) $earning->amount : 0,
        ];
    })->values();
});

Route::get('referrals/earnings', function (Request $request) {
    $me = $request->attributes->get('current_master');
    if (!$me) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $base = ReferralEarning::where('referrer_master_id', $me->id);

    return response()->json([
        'total_earned' => (int) (clone $base)->sum('amount'),
        'pending' => (int) (clone $base)->where('status', ReferralEarning::STATUS_PENDING)->sum('amount'),
        'paid' => (int) (clone $base)->where('status', ReferralEarning::STATUS_PAID)->sum('amount'),
        'counted_count' => \App\Models\Referral::where('referrer_master_id', $me->id)->where('status', Referral::STATUS_REWARDED)->count(),
    ]);
});
