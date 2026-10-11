<?php

namespace App\Http\Requests;

use App\Actions\Tickets\TicketInput;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;

class ChangeTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('ticket'));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $target = is_string($this->input('status')) ? TicketStatus::tryFrom($this->input('status')) : null;

        return TicketInput::statusRules($this->route('ticket')->status, $target);
    }
}
