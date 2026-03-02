<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Dto\EmailDTO;

class SendEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public $emailDTO;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(EmailDTO $emailDTO)
    {
        $this->emailDTO = $emailDTO;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject(trans('Contact Form'))
            ->view('emails.contact_form')
            ->with([
                'name' => $this->emailDTO->name,
                'phone' => $this->emailDTO->phone
            ]);
    }
}
