<?php

namespace App\Events;

use App\Models\DistributionRecord;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A distribution_records row was confirmed as actually sent (channel + date) -
 * see DistributionController::confirm(). Distinct from DocumentFinalApproved,
 * which only means the document *reached* approved_for_distribution.
 */
class DocumentDistributed
{
    use Dispatchable;

    public function __construct(
        public DistributionRecord $record,
        public User $actor,
    ) {}
}
