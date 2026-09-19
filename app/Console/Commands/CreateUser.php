<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the account that OAuth consent is granted by.
 *
 * There is no public registration: this server analyses chess for whoever runs it, and an
 * open sign-up form would only be a way in.
 */
class CreateUser extends Command
{
    protected $signature = 'chess:user {email} {--name=} {--password=}';

    protected $description = 'Create a user account for signing in and authorising MCP clients';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => (string) ($this->option('name') ?: strstr($email, '@', true)),
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->components->info("Created {$user->email}.");

        return self::SUCCESS;
    }
}
