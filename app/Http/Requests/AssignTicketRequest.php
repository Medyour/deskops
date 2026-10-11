<?php

namespace App\Http\Requests;

use App\Actions\Tickets\TicketInput;
use Illuminate\Foundation\Http\FormRequest;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('ticket'));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return TicketInput::assignmentRules();
    }
}
