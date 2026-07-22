<?php

declare(strict_types=1);

namespace Varsite\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/** Tworzy/aktualizuje konto administratora panelu (idempotentnie). */
final class AdminCreateCommand extends Command
{
    protected $signature = 'varsite:admin
        {--name=Administrator : Nazwa wyświetlana}
        {--email= : E-mail logowania (wymagany)}
        {--password= : Hasło (wymagane)}
        {--role=Właściciel : Rola wyświetlana w panelu}';

    protected $description = 'Tworzy lub aktualizuje konto administratora panelu';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $password = (string) $this->option('password');

        if ($email === '' || $password === '') {
            $this->error('Wymagane: --email oraz --password.');

            return self::INVALID;
        }

        // Model użytkownika z konfiguracji auth — działa z dowolnym modelem hosta (nie zakłada App\Models\User).
        /** @var class-string<Model> $model */
        $model = (string) config('auth.providers.users.model', \App\Models\User::class);

        $user = $model::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) $this->option('name'),
                'password' => Hash::make($password),
                'role' => (string) $this->option('role'),
            ],
        );

        $this->info(sprintf('Administrator gotowy: %s <%s> (id=%d)', $user->name, $user->email, $user->getKey()));

        return self::SUCCESS;
    }
}
