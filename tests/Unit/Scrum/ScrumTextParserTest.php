<?php

use App\Services\Scrum\ScrumTextParser;

describe('scrum text parser', function () {
    it('detects scrum standup format', function () {
        $parser = new ScrumTextParser;

        $text = "Tiko\nWhat Have Done\n- membuat homepage\n\nWhat To Do Next\n- styling CSS";
        expect($parser->isScrumFormat($text))->toBeTrue();

        $random = 'Hari ini saya memperbaiki bug pada halaman login';
        expect($parser->isScrumFormat($random))->toBeFalse();
    });

    it('parses single member scrum text', function () {
        $parser = new ScrumTextParser;

        $text = <<<'TEXT'
Tiko
What Have Done
- membuat desain homepage

Issue
- komponen website kurang

What To Do Next
- menyelesaikan desain homepage
- membuat komponen website yang kurang
- membuat draft wireframe halaman about us
TEXT;

        $parsed = $parser->parse($text);

        expect($parsed)->toHaveCount(1)
            ->and($parsed[0]['name'])->toBe('Tiko')
            ->and($parsed[0]['what_have_done'])->toContain('membuat desain homepage')
            ->and($parsed[0]['issue'])->toContain('komponen website kurang')
            ->and($parsed[0]['what_to_do_next'])->toContain('menyelesaikan desain homepage')
            ->and($parsed[0]['what_to_do_next'])->toContain('membuat komponen website yang kurang');
    });

    it('parses multi-member scrum text in one message', function () {
        $parser = new ScrumTextParser;

        $text = <<<'TEXT'
Tiko
What Have Done
- membuat homepage
Issue
- kurang aset
What To Do Next
- selesaikan homepage

Budi
What Have Done
- buat endpoint auth
What To Do Next
- integrasi payment
TEXT;

        $parsed = $parser->parse($text);

        expect($parsed)->toHaveCount(2)
            ->and($parsed[0]['name'])->toBe('Tiko')
            ->and($parsed[0]['what_have_done'])->toBe('- membuat homepage')
            ->and($parsed[0]['issue'])->toBe('- kurang aset')
            ->and($parsed[0]['what_to_do_next'])->toBe('- selesaikan homepage')
            ->and($parsed[1]['name'])->toBe('Budi')
            ->and($parsed[1]['what_have_done'])->toBe('- buat endpoint auth')
            ->and($parsed[1]['issue'])->toBeNull()
            ->and($parsed[1]['what_to_do_next'])->toBe('- integrasi payment');
    });
});
