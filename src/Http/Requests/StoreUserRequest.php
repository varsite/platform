<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autoryzacja przez Gate w kontrolerze
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $table = (new (config('auth.providers.users.model')))->getTable();
        $id = (int) $this->route('user');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique($table, 'email')->ignore($id)],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['nullable', 'string', Rule::in($this->availableRoles())],
        ];
    }

    /** @return list<string> */
    private function availableRoles(): array
    {
        return array_values(array_unique([
            ...(array) config('platform.auth.superuser_roles', []),
            ...array_keys((array) config('platform.auth.roles', [])),
        ]));
    }
}
