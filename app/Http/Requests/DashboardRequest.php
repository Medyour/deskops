<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardRequest extends FormRequest
{
    protected $redirectRoute = 'dashboard';

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Ticket::class);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', Rule::in($this->user()->isAdministrator() ? ['all'] : ['all', 'mine'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['scope.in' => 'Choisissez la vue Équipe ou Mes tickets.', 'scope.string' => 'Choisissez une seule vue du tableau de bord.'];
    }
}
