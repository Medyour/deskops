<?php

namespace App\Actions\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
        foreach (['id', 'reference', 'status', 'created_by', 'assigned_to', 'resolved_at', 'closed_at', 'version', 'role', 'created_at', 'updated_at'] as $field) {
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

        return Validator::make($input, $rules)->validate();
    }
}
