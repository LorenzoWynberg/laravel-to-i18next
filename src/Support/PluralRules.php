<?php

namespace OznerOmali\LaravelToI18nextLangParser\Support;

use MessageFormatter;

/**
 * The CLDR plural categories i18next resolves for a locale, and which counts fall in each.
 *
 * i18next picks a suffix with `Intl.PluralRules`, so the categories come from ICU (via ext-intl)
 * rather than from the plural table in Laravel, which only knows segment indexes.
 */
class PluralRules
{
    /**
     * The categories in the order i18next documents them.
     */
    public const array CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /** @var array<string, array<string, list<int|float>>> */
    private array $cache = [];

    /**
     * Every category the locale uses, mapped to sample counts that land in it, smallest first.
     *
     * Integers 1..200 cover every modulo-100 rule CLDR has; the large and fractional samples reach
     * `many` (fr, es, it… at a million) and categories only fractions select (`other` in ru, pl…).
     * Zero is left out: i18next answers a count of 0 with the `_zero` key before any category.
     *
     * @return array<string, list<int|float>>
     */
    public function samples(string $locale): array
    {
        return $this->cache[$locale] ??= $this->build($locale);
    }

    public function categoryFor(string $locale, int|float $count): string
    {
        $pattern = '{n, plural, '.implode(' ', array_map(
            static fn (string $category): string => "{$category}{{$category}}",
            self::CATEGORIES,
        )).'}';

        $category = MessageFormatter::formatMessage($this->icuLocale($locale), $pattern, ['n' => $count]);

        return is_string($category) && in_array($category, self::CATEGORIES, true) ? $category : 'other';
    }

    /**
     * @return array<string, list<int|float>>
     */
    private function build(string $locale): array
    {
        $byCategory = [];

        foreach ([...range(1, 200), 1000, 1000000, 1000001, 2000000] as $count) {
            $byCategory[$this->categoryFor($locale, $count)][] = $count;
        }

        // Fractions only stand in for a category no whole count reaches; nobody pluralises on 0.5,
        // and comparing Laravel's fractional output everywhere would only raise false warnings.
        foreach ([1.5, 0.5, 2.5] as $count) {
            $byCategory[$this->categoryFor($locale, $count)] ??= [$count];
        }

        // Keep i18next's documented order so generated files are stable and readable.
        return array_filter(array_merge(array_fill_keys(self::CATEGORIES, []), $byCategory));
    }

    /**
     * Laravel locale folders use `pt_BR`; ICU accepts that, but a folder like `pt-br` must be normalized.
     */
    private function icuLocale(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }
}
