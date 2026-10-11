<?php

namespace App\Http\Requests;

use App\Actions\Tickets\TicketInput;
use Illuminate\Foundation\Http\FormRequest;

abstract class TicketFormRequest extends FormRequest
{
    protected function getRedirectUrl(): string
    {
        return $this->validationRedirectUrl();
    }

    public function validationRedirectUrl(): string
    {
        return match ($this->route()?->getName()) {
            'tickets.store' => route('tickets.create'),
            'tickets.update' => route('tickets.edit', $this->route('ticket')),
            'tickets.assign', 'tickets.change-status', 'tickets.comment' => route('tickets.show', $this->route('ticket')),
            default => parent::getRedirectUrl(),
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TicketInput::messages();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return TicketInput::attributes();
    }
}
