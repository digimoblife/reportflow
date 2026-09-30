<?php

use App\Services\Ai\JsonOutput;
use App\Services\Ai\PromptRepository;
use App\Services\Ai\Schema\JsonSchemaValidator;

$validator = fn (array $schema) => new JsonSchemaValidator($schema);

it('checks types, including type lists and null', function () use ($validator) {
    $v = $validator(['type' => 'object', 'properties' => [
        'a' => ['type' => 'integer'], 'b' => ['type' => ['string', 'null']], 'c' => ['type' => 'number'], 'd' => ['type' => 'boolean'], 'e' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]]);

    expect($v->validate(['a' => 1, 'b' => null, 'c' => 1.5, 'd' => true, 'e' => ['x']]))->toBe([])
        ->and($v->validate(['a' => '1']))->toBe(['$.a:type'])
        ->and($v->validate(['a' => 1.5]))->toBe(['$.a:type'])
        ->and($v->validate(['b' => 5]))->toBe(['$.b:type'])
        ->and($v->validate(['c' => 'x']))->toBe(['$.c:type'])
        ->and($v->validate(['e' => ['x', 5]]))->toBe(['$.e[1]:type'])
        ->and($v->validate('not an object'))->toBe(['$:type']);
});

it('checks required, additionalProperties, enum, bounds, length and pattern', function () use ($validator) {
    $v = $validator(['type' => 'object', 'additionalProperties' => false, 'required' => ['n'], 'properties' => [
        'n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
        's' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4, 'pattern' => '^[a-z]+$'],
        'k' => ['type' => 'string', 'enum' => ['x', 'y']],
    ]]);

    expect($v->validate(['n' => 3, 's' => 'abc', 'k' => 'x']))->toBe([])
        ->and($v->validate([]))->toBe(['$.n:required'])
        ->and($v->validate(['n' => 0]))->toBe(['$.n:minimum'])
        ->and($v->validate(['n' => 6]))->toBe(['$.n:maximum'])
        ->and($v->validate(['n' => 1, 's' => 'a']))->toBe(['$.s:minLength'])
        ->and($v->validate(['n' => 1, 's' => 'abcde']))->toContain('$.s:maxLength')
        ->and($v->validate(['n' => 1, 's' => 'AB']))->toBe(['$.s:pattern'])
        ->and($v->validate(['n' => 1, 'k' => 'z']))->toBe(['$.k:enum'])
        ->and($v->validate(['n' => 1, 'extra' => 1]))->toBe(['$.extra:additionalProperties']);
});

it('checks oneOf and item counts', function () use ($validator) {
    $v = $validator(['type' => 'array', 'maxItems' => 2, 'items' => ['oneOf' => [['type' => 'null'], ['type' => 'string']]]]);

    expect($v->validate(['a', null]))->toBe([])
        ->and($v->validate([1]))->toBe(['$[0]:oneOf'])
        ->and($v->validate(['a', 'b', 'c']))->toBe(['$:maxItems']);
});

it('never puts the offending value into an error', function () use ($validator) {
    $secret = 'sk-'.'CANARY'.'0123456789abcdefghij';
    $errors = $validator(['type' => 'object', 'properties' => ['k' => ['type' => 'string', 'enum' => ['x']]]])->validate(['k' => $secret]);

    expect(json_encode($errors))->not->toContain($secret);
});

it('extracts JSON from plain replies, fences and surrounding prose', function (string $reply, ?array $expected) {
    expect(JsonOutput::decode($reply))->toBe($expected);
})->with([
    'plain' => ['{"a":1}', ['a' => 1]],
    'fenced' => ["```json\n{\"a\":1}\n```", ['a' => 1]],
    'fence without language' => ["```\n{\"a\":1}\n```", ['a' => 1]],
    'prose around' => ['Here you go: {"a":1} hope it helps', ['a' => 1]],
    'empty' => ['', null],
    'whitespace' => ["  \n ", null],
    'not json' => ['I cannot help with that', null],
    'truncated' => ['{"a":1,"b":', null],
    'scalar' => ['5', null],
]);

it('loads versioned prompts and keeps v1 unchanged', function () {
    $repo = new PromptRepository(dirname(__DIR__, 3).'/resources/prompts');
    $v1 = $repo->load('worklog_extraction@v1');

    // Changing v1 in place breaks this test on purpose: copy to v2.md, bump the version, run eval:run (skill prompt-eval).
    expect($v1['version'])->toBe('worklog_extraction@v1')
        ->and($v1['checksum'])->toBe('b363b8e9dd60e930fd99c41ec813e9e70226c3d2c5cdc2349198369d5f761017')
        ->and($v1['text'])->toContain('json')->toContain('candidates')->toContain('[REDACTED_SECRET]')
        ->and($repo->versions('worklog_extraction'))->toBe(['worklog_extraction@v1']);
});

it('rejects malformed or unknown prompt references', function (string $reference) {
    (new PromptRepository(dirname(__DIR__, 3).'/resources/prompts'))->load($reference);
})->with(['no version', 'worklog_extraction', '../secrets@v1', 'worklog_extraction@v99', 'x@1'])->throws(InvalidArgumentException::class);
