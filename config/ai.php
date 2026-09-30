<?php

/*
| AI provider selection. M2 only has the fake provider; the DeepSeek provider arrives in M3.
| Tests always use the fake provider.
*/
return [
    'provider' => env('AI_PROVIDER', 'fake'),
];
