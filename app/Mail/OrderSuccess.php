<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OrderSuccess extends Mailable
{
    use Queueable, SerializesModels;

    protected $orderNumber;
    protected $name;
    protected $deliveryDate;
    protected $businessAddress;
    protected $email;
    protected $tempFilePath;
    /**
     * Create a new message instance.
     */
    public function __construct($orderNumber, $name, $deliveryDate, $businessAddress, $tempFilePath)
    {
        $this->orderNumber = $orderNumber;
        $this->name = $name;
        $this->deliveryDate = $deliveryDate;
        $this->businessAddress = $businessAddress;
        $this->tempFilePath = $tempFilePath;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order Confirmation',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'order',
            with: [
                'orderNumber' => $this->orderNumber,
                'username' => $this->name,
                'date' => $this->deliveryDate,
                'orderaddress' => $this->businessAddress,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        // return [];
        // Log::info('file path in mail '.$this->tempFilePath);
        return [
            Attachment::fromStorage($this->tempFilePath)
                ->as($this->name.'-order-no-'.$this->orderNumber.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
