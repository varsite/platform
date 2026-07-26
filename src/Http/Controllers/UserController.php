<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Varsite\Platform\Http\Requests\StoreUserRequest;
use Varsite\Platform\Http\Requests\UpdateUserRequest;
use Varsite\Platform\Http\Resources\UserResource;
use Varsite\Platform\Support\Rbac;

/**
 * Zarządzanie kontami panelu — możliwość rdzenia platformy.
 *
 * Model użytkownika pochodzi z konfiguracji aplikacji (`auth.providers.users.model`),
 * więc Core nie zakłada konkretnej klasy hosta. Role są przypisywane wyłącznie
 * spośród tych, które zna konfiguracja platformy — nie da się nadać roli, która
 * nie istnieje, ani podnieść uprawnień poza to, co dopuszcza instalacja.
 */
final class UserController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('platform.users');

        $query = $this->model()::query()->latest('id');

        if (($search = $request->string('q')->toString()) !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (($role = $request->string('role')->toString()) !== '') {
            $query->where('role', $role);
        }

        return UserResource::collection($query->paginate(20));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('platform.users');

        $user = $this->model()::query()->create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            'role' => $request->string('role')->toString() ?: null,
        ]);

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(Request $request, int $user): UserResource
    {
        Gate::authorize('platform.users');

        return UserResource::make($this->model()::query()->findOrFail($user));
    }

    public function update(UpdateUserRequest $request, int $user): UserResource
    {
        Gate::authorize('platform.users');

        /** @var Model&Authenticatable $model */
        $model = $this->model()::query()->findOrFail($user);
        $data = array_filter([
            'name' => $request->string('name')->toString() ?: null,
            'email' => $request->string('email')->toString() ?: null,
        ]);

        if ($request->has('role')) {
            $data['role'] = $request->string('role')->toString() ?: null;
        }

        if (($password = $request->string('password')->toString()) !== '') {
            $data['password'] = Hash::make($password);
        }

        $model->fill($data)->save();

        return UserResource::make($model->refresh());
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        Gate::authorize('platform.users');

        /** @var Model&Authenticatable $model */
        $model = $this->model()::query()->findOrFail($user);

        // Konto, na którym trwa sesja, nie może usunąć samo siebie — inaczej
        // instalacja mogłaby zostać bez żadnego administratora.
        if ($request->user()?->getAuthIdentifier() === $model->getKey()) {
            return response()->json(['message' => 'Nie można usunąć konta, na którym trwa sesja.'], 422);
        }

        if ($this->isLastAdministrator($model)) {
            return response()->json(['message' => 'To ostatnie konto z pełnym dostępem — nie można go usunąć.'], 422);
        }

        $model->delete();

        return response()->json(null, 204);
    }

    /** Lista ról dostępnych w tej instalacji — zasila selektory w interfejsach. */
    public function roles(Rbac $rbac): JsonResponse
    {
        Gate::authorize('platform.users');

        $superusers = (array) config('platform.auth.superuser_roles', []);
        $named = array_keys((array) config('platform.auth.roles', []));

        $roles = array_map(
            static fn (string $role): array => ['id' => $role, 'name' => $role],
            array_values(array_unique([...$superusers, ...$named])),
        );

        return response()->json(['data' => $roles]);
    }

    private function isLastAdministrator(Model $user): bool
    {
        $superusers = (array) config('platform.auth.superuser_roles', []);

        if (! in_array($user->role, $superusers, true)) {
            return false;
        }

        return $this->model()::query()->whereIn('role', $superusers)->count() <= 1;
    }

    /** @return class-string<Model> */
    private function model(): string
    {
        /** @var class-string<Model> $model */
        $model = (string) config('auth.providers.users.model');

        return $model;
    }
}
