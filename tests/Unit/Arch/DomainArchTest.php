<?php

use App\Domain\Tasks\InvalidTaskStatusTransition;
use App\Domain\Tasks\SameStatusTransition;
use App\Domain\Tasks\TaskStatusTransition;
use App\Enums\TaskStatus;

arch('TaskStatusTransition only depends on the TaskStatus enum and its own exceptions')
    ->expect(TaskStatusTransition::class)
    ->toOnlyUse([TaskStatus::class, InvalidTaskStatusTransition::class, SameStatusTransition::class]);

arch('the domain layer is framework-free')
    ->expect('App\Domain')
    ->not->toUse('Illuminate');

arch('enums are framework-free string-backed enums')
    ->expect('App\Enums')
    ->not->toUse('Illuminate');

arch('enums are backed enums')
    ->expect('App\Enums')
    ->classes()
    ->toBeStringBackedEnums();
