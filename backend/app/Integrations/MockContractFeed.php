<?php

namespace App\Integrations;

use App\Models\Contract;

final class MockContractFeed implements ContractFeed
{
    public function provenance(): array
    {
        return [
            'source' => 'Contract Management (mocked, read-only)',
            'read_only' => true,
            'as_of' => now()->toIso8601String(),
        ];
    }

    public function refresh(Contract $contract): void
    {
        $contract->forceFill([
            'synced_at' => now(),
        ])->save();
    }
}
