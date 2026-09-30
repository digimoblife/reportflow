<?php

/*
| Extra redaction patterns (PRD §56 "pola tambahan yang dapat dikonfigurasi per user").
| Full PCRE patterns with delimiters and the `u` flag, e.g. '~\bACME-[A-Z0-9]{12}\b~u'.
| The whole match is replaced. Keep quantifiers bounded ({1,256}, never + or * on broad classes).
| Per-user patterns move to a users column when the settings UI lands (M5).
*/
return [
    'max_input_length' => 50_000,

    'extra_patterns' => [],

    // user_id => list of patterns
    'user_patterns' => [],
];
