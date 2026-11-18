<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'user:admin')]
class MakeAdmin extends Command
{
    protected $signature = 'user:admin {email} {--revoke}';

    protected $description = 'Grant (or revoke) admin access to the financial report.';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
        $this->info($user->email.($user->is_admin ? ' is now an admin.' : ' is no longer an admin.'));

        return self::SUCCESS;
    }
}
