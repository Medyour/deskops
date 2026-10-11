<?php

namespace App\Http\Requests;

use App\Actions\Tickets\TicketInput;
use App\Models\Ticket;

class StoreTicketRequest extends TicketFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Ticket::class);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return TicketInput::creationRules();
    }
}
