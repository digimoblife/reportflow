<?php

use App\Models\Contracts\UserScoped;
use App\Models\User;

/*
 * CLAUDE.md rule 11: every model must be scoped per user. A new model that forgets the
 * UserScoped contract fails here; User is the only allowed exception (it is the scope).
 */
arch('every model except User is user-scoped')
    ->expect('App\Models')
    ->classes()
    ->toImplement(UserScoped::class)
    ->ignoring([User::class, 'App\Models\Concerns', 'App\Models\Contracts', 'App\Models\Scopes']);
