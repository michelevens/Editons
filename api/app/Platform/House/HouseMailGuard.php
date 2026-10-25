<?php

namespace App\Platform\House;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

/**
 * No email to a house publisher account, whatever sent it.
 *
 * Notify::send already refuses one, but mail also leaves through Laravel
 * notifications (a password reset) and the email log's resend. This sits on
 * the mailer itself (MessageSending): a message with a house address among
 * its recipients is not sent at all. The addresses are on a reserved
 * domain that cannot deliver anyway; this makes sure nothing even tries.
 */
final class HouseMailGuard
{
    /** false cancels the message; null lets it go and the other listeners run. */
    public static function allows(MessageSending $event): ?bool
    {
        $message = $event->message;

        if (! $message instanceof Email) {
            return null;
        }

        foreach ([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()] as $address) {
            if (House::isHouseAddress($address->getAddress())) {
                Log::info('Email to a house account not sent', ['subject' => $message->getSubject()]);

                return false;
            }
        }

        return null;
    }
}
