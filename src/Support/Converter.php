<?php

namespace OznerOmali\LaravelToI18nextLangParser\Support;

use Illuminate\Translation\MessageSelector;

/**
 * Turns one Laravel translation file (as a PHP array) into the i18next JSON for it.
 *
 * Plurals are not re-parsed here. For every plural category i18next can ask for in the locale,
 * the MessageSelector from Laravel itself is asked what `trans_choice` would print, so any Laravel syntax —
 * `a|b`, `{0}`, `[2,*]`, `[1,19]`, three plain segments — converts to exactly what Laravel renders.
 * When Laravel prints different text for counts i18next cannot tell apart, the conversion is lossy
 * and a warning says so instead of guessing silently.
 */
class Converter
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly PluralRules $rules = new PluralRules,
        private readonly MessageSelector $selector = new MessageSelector,
        private readonly bool $stripHtmlAttributes = true,
    ) {}

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<array-key, mixed>
     */
    public function convert(array $lines, string $locale, string $prefix = ''): array
    {
        if ($prefix === '') {
            $this->warnings = [];
        }

        $output = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $this->put($output, $key, $this->convert($value, $locale, $path), $path);
            } elseif (! is_string($value)) {
                $this->put($output, $key, $value, $path);
            } elseif (! str_contains($value, '|')) {
                $this->put($output, $key, $this->convertText($value), $path);
            } else {
                foreach ($this->pluralForms($value, $locale, $path) as $category => $text) {
                    $this->put($output, "{$key}_{$category}", $text, $path);
                }
            }
        }

        return $output;
    }

    /**
     * Problems found by the last convert() call, one line each, prefixed with the key path.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Replace the `:key` placeholders Laravel uses with i18next interpolations, mirroring how the
     * Translator matches them: `:key` verbatim, `:Key` as ucfirst, `:KEY` as uppercase.
     *
     *  - `:name`    → {{name}}
     *  - `:dueDate` → {{dueDate}}
     *  - `:Name`    → {{name, capitalize}}
     *  - `:NAME`    → {{name, uppercase}}
     *
     * A placeholder starts with a letter or underscore, so `10:30` and `3:1` stay literal text.
     */
    public function convertText(string $text): string
    {
        if ($this->stripHtmlAttributes) {
            $text = preg_replace('/<(\w+)\s[^>]*?(\/?)>/', '<$1$2>', $text) ?? $text;
        }

        return (string) preg_replace_callback(
            '/:([A-Za-z_]\w*)/',
            static function (array $match): string {
                $name = $match[1];

                if (strlen($name) > 1 && $name === strtoupper($name) && $name !== strtolower($name)) {
                    return '{{'.strtolower($name).', uppercase}}';
                }

                if (ctype_upper($name[0])) {
                    return '{{'.lcfirst($name).', capitalize}}';
                }

                return '{{'.$name.'}}';
            },
            $text,
        );
    }

    /**
     * @return array<string, string> plural category => converted text
     */
    private function pluralForms(string $line, string $locale, string $path): array
    {
        $samples = $this->rules->samples($locale);
        $zeroCategory = $this->rules->categoryFor($locale, 0);

        // A locale with a CLDR `zero` category (lv, ar…) serves a count of 0 from it as well.
        if (isset($samples['zero'])) {
            array_unshift($samples['zero'], 0);
        }

        $forms = [];

        foreach ($samples as $category => $counts) {
            $texts = array_map(fn (int|float $count): string => $this->choose($line, $count, $locale), $counts);
            $forms[$category] = $this->convertText($texts[0]);

            foreach ($texts as $index => $text) {
                if ($text !== $texts[0]) {
                    $this->warnings[] = "{$path}: Laravel prints different text for {$counts[0]} and {$counts[$index]}, "
                        ."which i18next both resolve to the `{$category}` form for [{$locale}]; using the text for {$counts[0]}.";

                    break;
                }
            }
        }

        // i18next answers a count of 0 with `_zero` when it exists, in every language. Only emit it
        // when Laravel's text for 0 differs from what the category would give — an empty or copied
        // `_zero` is never needed.
        $zero = $this->convertText($this->choose($line, 0, $locale));
        if (! isset($samples['zero']) && $zero !== ($forms[$zeroCategory] ?? null)) {
            return ['zero' => $zero] + $forms;
        }

        return $forms;
    }

    private function choose(string $line, int|float $count, string $locale): string
    {
        $chosen = $this->selector->choose($line, $count, $locale);

        return is_string($chosen) ? trim($chosen) : '';
    }

    /**
     * @param  array<array-key, mixed>  $output
     */
    private function put(array &$output, int|string $key, mixed $value, string $path): void
    {
        if (array_key_exists($key, $output)) {
            $this->warnings[] = "{$path}: generates the key `{$key}`, which another key in the same file also generates; the later one wins.";
        }

        $output[$key] = $value;
    }
}
