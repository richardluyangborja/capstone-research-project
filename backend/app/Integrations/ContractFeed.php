<?php

namespace App\Integrations;

use App\Models\Contract;

interface ContractFeed
{
    /**
     * @return array{source: string, read_only: bool, as_of: string}
     */
    public function provenance(): array;

    public function refresh(Contract $contract): void;
}
