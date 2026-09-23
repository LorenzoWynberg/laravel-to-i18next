<?php

use OznerOmali\LaravelToI18nextLangParser\Support\PluralRules;

it('knows the categories i18next resolves per locale', function (string $locale, array $categories): void {
    expect(array_keys((new PluralRules)->samples($locale)))->toBe($categories);
})->with([
    'en' => ['en', ['one', 'other']],
    'fr' => ['fr', ['one', 'many', 'other']],
    'ru' => ['ru', ['one', 'few', 'many', 'other']],
    'ar (zero is only 0, which _zero answers)' => ['ar', ['one', 'two', 'few', 'many', 'other']],
    'lv (zero covers 10-20 too)' => ['lv', ['zero', 'one', 'other']],
    'ja' => ['ja', ['other']],
    'underscore region' => ['pt_BR', ['one', 'many', 'other']],
    'dash region' => ['pt-br', ['one', 'many', 'other']],
]);

it('categorises a count', function (): void {
    $rules = new PluralRules;

    expect($rules->categoryFor('en', 1))->toBe('one')
        ->and($rules->categoryFor('en', 0))->toBe('other')
        ->and($rules->categoryFor('fr', 0))->toBe('one')
        ->and($rules->categoryFor('fr', 1000000))->toBe('many');
});
