<?php

declare(strict_types=1);

use App\Services\PromptBuilder;

test('system prompt describes the assistant persona with no context by default', function () {
    $builder = new PromptBuilder;

    $prompt = $builder->buildSystemPrompt();

    expect($prompt)->toContain('CompanyAIChatbot assistant')
        ->and($prompt)->not->toContain('company document excerpts');
});

test('system prompt appends context when provided', function () {
    $builder = new PromptBuilder;

    $prompt = $builder->buildSystemPrompt('Some retrieved text.');

    expect($prompt)->toContain('Some retrieved text.');
});

test('user prompt is just the message when there is no history', function () {
    $builder = new PromptBuilder;

    $prompt = $builder->buildUserPrompt('Hello');

    expect($prompt)->toBe('Hello');
});

test('user prompt flattens history into User/Assistant turns', function () {
    $builder = new PromptBuilder;

    $prompt = $builder->buildUserPrompt('And after that?', [
        ['role' => 'user', 'content' => 'What is the capital of France?'],
        ['role' => 'assistant', 'content' => 'Paris.'],
    ]);

    expect($prompt)->toBe(
        "User: What is the capital of France?\nAssistant: Paris.\nUser: And after that?"
    );
});
