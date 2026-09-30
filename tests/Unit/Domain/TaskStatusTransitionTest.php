<?php

use App\Domain\Tasks\InvalidTaskStatusTransition;
use App\Domain\Tasks\SameStatusTransition;
use App\Domain\Tasks\TaskStatusTransition;
use App\Enums\TaskStatus;

/*
 * Full transition matrix, written out literally from PRD §14 (not derived from the class).
 * Rows = from, columns = to. 1 = allowed, 0 = rejected. Same-status is always rejected.
 *
 *                 draft open in_progress waiting blocked completed cancelled
 */
const TASK_STATUS_EXPECTED_MATRIX = [
    'draft' => [0, 1, 1, 1, 1, 1, 0],
    'open' => [0, 0, 1, 1, 1, 1, 1],
    'in_progress' => [0, 0, 0, 1, 1, 1, 1],
    'waiting' => [0, 0, 1, 0, 1, 1, 1],
    'blocked' => [0, 0, 1, 1, 0, 1, 1],
    'completed' => [0, 0, 1, 0, 0, 0, 0],
    'cancelled' => [0, 1, 0, 0, 0, 0, 0],
];

const TASK_STATUS_COLUMNS = ['draft', 'open', 'in_progress', 'waiting', 'blocked', 'completed', 'cancelled'];

dataset('every status pair', function () {
    foreach (TASK_STATUS_EXPECTED_MATRIX as $from => $row) {
        foreach (TASK_STATUS_COLUMNS as $i => $to) {
            yield "{$from} -> {$to}" => [TaskStatus::from($from), TaskStatus::from($to), (bool) $row[$i]];
        }
    }
});

it('covers every status in the expected matrix', function () {
    expect(array_keys(TASK_STATUS_EXPECTED_MATRIX))->toEqualCanonicalizing(TaskStatus::values())
        ->and(TASK_STATUS_COLUMNS)->toEqualCanonicalizing(TaskStatus::values());
});

it('matches PRD §14 for every pair', function (TaskStatus $from, TaskStatus $to, bool $allowed) {
    expect(TaskStatusTransition::canTransition($from, $to))->toBe($allowed);

    if ($allowed) {
        TaskStatusTransition::assertCanTransition($from, $to);
        expect(TaskStatusTransition::allowedFrom($from))->toContain($to);
    } else {
        expect(fn () => TaskStatusTransition::assertCanTransition($from, $to))
            ->toThrow($from === $to ? SameStatusTransition::class : InvalidTaskStatusTransition::class);
        expect(TaskStatusTransition::allowedFrom($from))->not->toContain($to);
    }
})->with('every status pair');

it('returns exactly the allowed targets for each status', function (TaskStatus $from) {
    $expected = [];
    foreach (TASK_STATUS_EXPECTED_MATRIX[$from->value] as $i => $flag) {
        if ($flag === 1) {
            $expected[] = TaskStatus::from(TASK_STATUS_COLUMNS[$i]);
        }
    }

    expect(TaskStatusTransition::allowedFrom($from))->toEqualCanonicalizing($expected);
})->with(TaskStatus::cases());

it('never allows a same-status transition and reports it distinctly', function (TaskStatus $status) {
    expect(TaskStatusTransition::canTransition($status, $status))->toBeFalse()
        ->and(TaskStatusTransition::allowedFrom($status))->not->toContain($status);

    try {
        TaskStatusTransition::assertCanTransition($status, $status);
        $this->fail('Expected SameStatusTransition');
    } catch (SameStatusTransition $e) {
        expect($e)->toBeInstanceOf(InvalidTaskStatusTransition::class)
            ->and($e->from)->toBe($status)
            ->and($e->to)->toBe($status);
    }
})->with(TaskStatus::cases());

it('exposes from and to on invalid transitions', function () {
    try {
        TaskStatusTransition::assertCanTransition(TaskStatus::Draft, TaskStatus::Cancelled);
        $this->fail('Expected InvalidTaskStatusTransition');
    } catch (InvalidTaskStatusTransition $e) {
        expect($e)->not->toBeInstanceOf(SameStatusTransition::class)
            ->and($e->from)->toBe(TaskStatus::Draft)
            ->and($e->to)->toBe(TaskStatus::Cancelled)
            ->and($e->getMessage())->toContain('[draft]')->toContain('[cancelled]');
    }
});
