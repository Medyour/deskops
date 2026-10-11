<?php

namespace App\Actions\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class TicketInput
{
    /** @return array<string, array<mixed>> */
    public static function creationRules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'min:5', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'requester_name' => ['required', 'string', 'min:2', 'max:120'],
            'requester_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'priority' => ['sometimes', 'required', Rule::enum(TicketPriority::class)],
        ];
        foreach (['id', 'reference', 'status', 'created_by', 'assigned_to', 'resolved_at', 'closed_at', 'version', 'role', 'is_demo', 'created_at', 'updated_at'] as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    /** @return array<string, array<mixed>> */
    public static function assignmentRules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'assigned_to' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Agent->value)],
            'status' => ['missing'], 'created_by' => ['missing'],
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function statusRules(?TicketStatus $current = null, ?TicketStatus $target = null): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'reason' => $current !== null && $target !== null && $target !== $current && $current->requiresReasonFor($target)
                ? ['required', 'string', 'min:5', 'max:500'] : ['nullable', 'string', 'max:500'],
            'assigned_to' => ['missing'], 'resolved_at' => ['missing'], 'closed_at' => ['missing'],
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function updateRules(): array
    {
        $rules = self::creationRules();
        $rules['priority'] = ['required', Rule::enum(TicketPriority::class)];
        $rules['version'] = ['required', 'integer', 'min:1'];

        return $rules;
    }

    /** @return array<string, array<mixed>> */
    public static function commentRules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'min:1', 'max:5000', 'regex:/[^\s\p{Z}]/u'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'present' => 'Le champ :attribute doit être transmis.',
            'string' => 'Le champ :attribute doit être un texte.',
            'integer' => 'Le champ :attribute doit être un entier.',
            'min.string' => 'Le champ :attribute doit contenir au moins :min caractères.',
            'max.string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
            'min.numeric' => 'Le champ :attribute doit être au moins égal à :min.',
            'email' => 'Saisissez une adresse e-mail valide.',
            Enum::class => 'La valeur du champ :attribute est inconnue.',
            'exists' => 'Choisissez un agent existant.',
            'in' => 'La valeur du champ :attribute est inconnue.',
            'regex' => 'Le champ :attribute doit contenir du texte non blanc.',
            'missing' => 'Le champ :attribute ne peut pas être défini.',
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'title' => 'sujet', 'description' => 'description', 'requester_name' => 'nom du demandeur',
            'requester_email' => 'e-mail du demandeur', 'priority' => 'priorité', 'status' => 'statut',
            'assigned_to' => 'agent assigné', 'version' => 'version', 'body' => 'commentaire',
            'reason' => 'motif', 'q' => 'recherche', 'assignee' => 'assignation', 'page' => 'page',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }

        return Validator::make($input, $rules, self::messages(), self::attributes())->validate();
    }
}
