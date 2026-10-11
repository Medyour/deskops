<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DemoLoginRequest extends FormRequest
{
    protected $redirectRoute = 'login';

    public function authorize(): bool
    {
        abort_unless(config('app.demo_mode'), 404);

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::in(['administrator', 'agent'])]];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['role', '_token']) !== []) {
                $validator->errors()->add('role', 'Seul le rôle de démonstration peut être sélectionné.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['role.required' => 'Choisissez un rôle de démonstration.', 'role.in' => 'Ce rôle de démonstration est inconnu.', 'role.string' => 'Choisissez un rôle de démonstration valide.'];
    }
}
