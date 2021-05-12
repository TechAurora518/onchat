<?php

declare(strict_types=1);

namespace app\listener\task;

use app\core\mail\Mailer;

class SendMail
{
    public function handle($event)
    {
        [
            'from'      => $from,
            'addresses' => $addresses,
            'isHTML'    => $isHTML,
            'subject'   => $subject,
            'body'      => $body,
            'altBody'   => $altBody,
        ] = $event;

        $mailer = Mailer::create()
            ->setFrom(...$from)
            ->isHTML($isHTML)
            ->setSubject($subject)
            ->setBody($body)
            ->setAltBody($altBody);

        foreach ($addresses as $address) {
            $mailer->addAddress($address);
        }

        $mailer->send();
    }
}
