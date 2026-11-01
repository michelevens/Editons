<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI research assistant
    |--------------------------------------------------------------------------
    |
    | Who answers "Research this topic" and "Find sources for this claim"
    | (ADR — AI research, source-backed fact-checking, cover templates).
    | 'anthropic' asks Claude with Anthropic's server-side web search and
    | web fetch tools, and returns only sources the model actually
    | retrieved. 'fake' returns clearly labelled sample sources on
    | example.org, for tests and development; it calls nobody and is never
    | charged. It follows the editorial driver unless set on its own.
    |
    */

    'driver' => env('RESEARCH_DRIVER', env('EDITORIAL_DRIVER', 'fake') === 'anthropic' ? 'anthropic' : 'fake'),
    'apiKey' => env('ANTHROPIC_API_KEY'),
    'model' => env('RESEARCH_MODEL', 'claude-sonnet-5-5'),
    'maxTokens' => (int) env('RESEARCH_MAX_TOKENS', 8000),
    'timeout' => (int) env('RESEARCH_TIMEOUT', 180),

    // How far one run may go. Each search is billed by the provider; a
    // fetch costs only the tokens of the page it reads.
    'maxSearches' => (int) env('RESEARCH_MAX_SEARCHES', 5),
    'maxFetches' => (int) env('RESEARCH_MAX_FETCHES', 4),
    // The longest page a fetch brings into the conversation, in tokens.
    'maxPageTokens' => (int) env('RESEARCH_MAX_PAGE_TOKENS', 6000),
    // A paused server-tool turn is resumed at most this many times.
    'maxContinuations' => 2,
    'maxSources' => 8,

    // Anthropic's published price for a web search: $10 per 1,000.
    'webSearchUsd' => 0.01,

    // Sites never searched or fetched. Empty by default.
    'blockedDomains' => array_values(array_filter(explode(',', (string) env('RESEARCH_BLOCKED_DOMAINS', '')))),

];
