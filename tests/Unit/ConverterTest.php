<?php

use Illuminate\Translation\MessageSelector;
use OznerOmali\LaravelToI18nextLangParser\Support\Converter;
use OznerOmali\LaravelToI18nextLangParser\Support\PluralRules;

/**
 * Resolve a key the way i18next does: `_zero` for a count of 0 when present, otherwise the
 * suffix of the count's CLDR category. A missing form is a miss (i18next would print the key).
 *
 * @param  array<array-key, mixed>  $converted
 */
function i18nextResolve(array $converted, string $key, int|float $count, string $locale): ?string
{
    if ($count === 0 && isset($converted["{$key}_zero"])) {
        return $converted["{$key}_zero"];
    }

    $category = (new PluralRules)->categoryFor($locale, $count);

    return $converted["{$key}_{$category}"] ?? null;
}

describe('plurals', function (): void {
    it('matches trans_choice for every count i18next can ask for', function (string $line, string $locale): void {
        $converter = new Converter;
        $converted = $converter->convert(['k' => $line], $locale);

        expect($converter->warnings())->toBe([]);

        foreach ([...range(0, 30), 100, 101, 111, 1000, 1000000] as $count) {
            $laravel = $converter->convertText(trim((new MessageSelector)->choose($line, $count, $locale)));

            expect(i18nextResolve($converted, 'k', $count, $locale))
                ->toBe($laravel, "count {$count} in [{$locale}]");
        }
    })->with([
        'simple pair' => 'apple|apples',
        'identical pair' => 'Selected|Selected',
        'explicit zero' => '{0} No :resource|{1} One :resource|[2,*] :count :resource',
        'no zero branch' => '{1} An error occurred.|[2,*] Errors occurred.',
        'no spaces after conditions' => '{0}None|{1}One|[2,*]Many',
        'zero via range' => '[0,1] Few|[2,*] Many',
    ])->with(['en', 'es', 'fr', 'de', 'pt_BR']);

    it('never emits an empty or redundant _zero form', function (): void {
        $converted = (new Converter)->convert(['k' => '{1} One error|[2,*] Many errors'], 'en');

        expect($converted)->toBe(['k_one' => 'One error', 'k_other' => 'Many errors']);
    });

    it('emits _zero when Laravel prints something specific for zero', function (): void {
        $converted = (new Converter)->convert(['k' => '{0} None|{1} One|[2,*] Many'], 'en');

        expect($converted)->toBe(['k_zero' => 'None', 'k_one' => 'One', 'k_other' => 'Many']);
    });

    it('emits every category the locale needs, so large counts never show the raw key', function (): void {
        $converted = (new Converter)->convert(['k' => 'élément|éléments'], 'fr');

        expect($converted)->toBe(['k_one' => 'élément', 'k_many' => 'éléments', 'k_other' => 'éléments']);
    });

    it('covers languages with many plural forms', function (): void {
        $converted = (new Converter)->convert(['k' => 'яблоко|яблока|яблок'], 'ru');

        expect(array_keys($converted))->toBe(['k_one', 'k_few', 'k_many', 'k_other'])
            ->and($converted['k_few'])->toBe('яблока')
            ->and($converted['k_many'])->toBe('яблок');
    });

    it('always answers zero in a language whose zero category is 0 alone', function (): void {
        $converted = (new Converter)->convert(['k' => 'a|b|c|d|e|f'], 'ar');

        expect(array_keys($converted))->toBe(['k_zero', 'k_one', 'k_two', 'k_few', 'k_many', 'k_other']);
    });

    it('checks 0 against the rest of a CLDR zero category', function (): void {
        $converter = new Converter;

        // Latvian puts 0 and 10–20 in `zero`; Laravel's explicit {0} text cannot apply to both.
        expect($converter->convert(['k' => '{0} nav|{1} viens|[2,*] daudzi'], 'lv'))
            ->toHaveKey('k_zero', 'nav')
            ->and($converter->warnings()[0])->toContain('different text for 0 and 10');
    });

    it('warns instead of guessing when i18next cannot express a Laravel range', function (): void {
        $converter = new Converter;
        $converted = $converter->convert(['k' => '{0} none|[1,19] some|[20,*] many'], 'en');

        expect($converted)->toBe(['k_zero' => 'none', 'k_one' => 'some', 'k_other' => 'some'])
            ->and($converter->warnings())->toHaveCount(1)
            ->and($converter->warnings()[0])->toContain('k: Laravel prints different text for 2 and 20');
    });

    it('treats three plain segments the way Laravel does for the locale', function (): void {
        $converter = new Converter;

        expect($converter->convert(['k' => 'one|two|three'], 'en'))->toBe(['k_one' => 'one', 'k_other' => 'two'])
            ->and($converter->warnings())->toBe([]);
    });

    it('warns when a plural collides with an explicit key', function (): void {
        $converter = new Converter;
        $converter->convert(['k' => 'a|b', 'k_one' => 'x'], 'en');

        expect($converter->warnings())->toBe(['k_one: generates the key `k_one`, which another key in the same file also generates; the later one wins.']);
    });

    it('resets warnings between files', function (): void {
        $converter = new Converter;
        $converter->convert(['k' => '[1,5] a|[6,*] b'], 'en');
        $converter->convert(['k' => 'a|b'], 'en');

        expect($converter->warnings())->toBe([]);
    });
});

describe('placeholders', function (): void {
    it('converts placeholders the way Laravel matches them', function (string $laravel, string $i18next): void {
        expect((new Converter)->convertText($laravel))->toBe($i18next);
    })->with([
        'plain' => ['Hi :name', 'Hi {{name}}'],
        'camelCase is kept' => ['Order #:orderId', 'Order #{{orderId}}'],
        'snake_case' => [':first_name', '{{first_name}}'],
        'ucfirst' => [':Resource created', '{{resource, capitalize}} created'],
        'ucfirst camelCase' => [':AppName', '{{appName, capitalize}}'],
        'uppercase' => ['Hey :NAME', 'Hey {{name, uppercase}}'],
        'single capital' => [':X', '{{x, capitalize}}'],
        'times stay literal' => ['Starts at 10:30', 'Starts at 10:30'],
        'ratios stay literal' => ['Ratio 3:1', 'Ratio 3:1'],
        'urls stay literal' => ['Visit https://example.com', 'Visit https://example.com'],
        'i18next syntax passes through' => ['{{percent}}% off', '{{percent}}% off'],
        'several' => [':count :resource by :User', '{{count}} {{resource}} by {{user, capitalize}}'],
    ]);
});

describe('html', function (): void {
    it('strips attributes and keeps self-closing tags', function (): void {
        expect((new Converter)->convertText('<b class="x">:name</b><br/><br class="y" /><a href="/z">z</a>'))
            ->toBe('<b>{{name}}</b><br/><br/><a>z</a>');
    });

    it('keeps attributes when told to', function (): void {
        expect(new Converter(stripHtmlAttributes: false)->convertText('<a href="/z">z</a>'))
            ->toBe('<a href="/z">z</a>');
    });
});

describe('structure', function (): void {
    it('keeps nesting, lists and non-string values', function (): void {
        $converted = (new Converter)->convert([
            'nested' => ['deep' => ['key' => ':Name']],
            'list' => ['a', 'b'],
            'flat.dotted' => 'kept flat',
            'number' => 5,
            'flag' => true,
            'nothing' => null,
        ], 'en');

        expect($converted)->toBe([
            'nested' => ['deep' => ['key' => '{{name, capitalize}}']],
            'list' => ['a', 'b'],
            'flat.dotted' => 'kept flat',
            'number' => 5,
            'flag' => true,
            'nothing' => null,
        ]);
    });

    it('prefixes nested warnings with the full key path', function (): void {
        $converter = new Converter;
        $converter->convert(['a' => ['b' => '[1,5] few|[6,*] many']], 'en');

        expect($converter->warnings()[0])->toStartWith('a.b: ');
    });
});
