<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ops\Purger;
use App\Support\UserContext;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Permanent deletion of client data (PRD §57). Dry run unless --force; asks for confirmation before deleting. Prints
 * counts only, never titles or text.
 */
class Purge extends Command
{
    protected $signature = 'reportflow:purge
        {target : activity|task|project|report|message|user}
        {id? : Record id (omit for target "user")}
        {--user= : Owner user id (default: the only user)}
        {--with-activities : For "message": also delete the activities it created}
        {--with-messages : Also delete the messages the purged activities and task events came from, with their AI logs}
        {--force : Actually delete (without it nothing changes)}';

    protected $description = 'Permanently delete client data (dry run by default)';

    public function handle(Purger $purger, UserContext $context): int
    {
        $userId = $this->resolveUser($context);

        if ($userId === null) {
            return self::FAILURE;
        }

        $target = (string) $this->argument('target');
        $id = $this->argument('id') === null ? null : (int) $this->argument('id');

        try {
            $plan = $purger->plan($target, $id, $userId, (bool) $this->option('with-activities'), (bool) $this->option('with-messages'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Table', 'Rows'], collect($plan->counts())->map(fn (int $n, string $table): array => [$table, $n])->values()->all());
        $this->line('Report files on disk: '.count($plan->files));

        foreach ($plan->notes as $note => $count) {
            $this->components->warn("{$note}: {$count}");
        }

        if ($plan->isEmpty()) {
            $this->components->info('Nothing to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->components->info('Dry run: nothing was deleted. Re-run with --force to delete permanently.');

            return self::SUCCESS;
        }

        if (! $this->confirm('This permanently deletes the rows above and cannot be undone. Continue?', false)) {
            $this->components->warn('Cancelled.');

            return self::FAILURE;
        }

        $purger->execute($plan, $userId);
        $this->components->info('Deleted.');

        return self::SUCCESS;
    }

    private function resolveUser(UserContext $context): ?int
    {
        $option = $this->option('user');

        if ($option !== null) {
            $exists = $context->runAsSystem(fn () => User::query()->whereKey((int) $option)->exists());

            if (! $exists) {
                $this->components->error('User not found.');

                return null;
            }

            return (int) $option;
        }

        $ids = $context->runAsSystem(fn () => User::query()->limit(2)->pluck('id'));

        if ($ids->count() !== 1) {
            $this->components->error('Pass --user=ID (there is not exactly one user).');

            return null;
        }

        return (int) $ids->first();
    }
}
