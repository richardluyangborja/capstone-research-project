<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractRateCardResource;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContractRateCardController extends Controller
{
    public function index(Request $request, Contract $contract): AnonymousResourceCollection
    {
        $this->authorize('view', $contract);

        return ContractRateCardResource::collection(
            $contract->rateCards()->with('site:id,label')->get(),
        )->additional([
            'meta' => [
                'source' => 'Contract Management (mocked, read-only)',
                'read_only' => true,
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }
}
