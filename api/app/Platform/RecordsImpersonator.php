<?php

namespace App\Platform;

/**
 * Stamp who was really at the keyboard on rows written during "View as".
 *
 * On the records that say who changed a manuscript or spent credits
 * (versions, comments, reviews, the ledger), so a support session's edits
 * are never indistinguishable from the author's own.
 */
trait RecordsImpersonator
{
    public static function bootRecordsImpersonator(): void
    {
        static::creating(function ($model) {
            if ($model->getAttribute('impersonator_id') === null) {
                $id = Impersonation::impersonatorId();

                if ($id !== null) {
                    $model->setAttribute('impersonator_id', $id);
                }
            }
        });
    }
}
