<?php

namespace OznerOmali\LaravelToI18nextLangParser\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use OznerOmali\LaravelToI18nextLangParser\Support\VersionHandler;
use Throwable;

class ParseLangToI18NextCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lang:to-i18next {locale?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse Laravel language files into i18next-compatible JSON';

    protected VersionHandler $versionHandler;

    public function __construct(VersionHandler $versionHandler)
    {
        parent::__construct();
        $this->versionHandler = $versionHandler;
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function handle(): int
    {
        $langPath = lang_path();
        if (! File::isDirectory($langPath)) {
            $this->error("lang/ directory not found at: $langPath");

            return 1;
        }

        /** @var string[] $dirs */
        $dirs = File::directories($langPath);

        /** @var \Illuminate\Support\Collection<int,string> $allLocales */
        $allLocales = collect($dirs)
            ->map(fn (string $dir): string => basename($dir));

        /** @var ?string $requested */
        $requested = $this->argument('locale');

        if ($requested) {
            if (! $allLocales->contains($requested)) {
                $this->error("Locale [$requested] does not exist in $langPath");

                return 1;
            }
            $locales = collect([$requested]);
        } else {
            $locales = $allLocales;
        }

        foreach ($locales as $locale) {
            $this->info("→ Exporting locale: $locale");
            $files = File::allFiles("$langPath/$locale");

            foreach ($files as $file) {
                // Get the relative path e.g. 'validation.php' or 'nested/foo.php'
                $relPath = $file->getRelativePathname();

                // Strip ".php"
                $key = substr($relPath, 0, -4);

                // Export the file
                $this->exportFile($locale, $key, $file->getPathname());
            }
            // ✅ Update version
            $this->versionHandler->update($locale);
        }
        $this->info('✅ All translations exported!');

        return 0;
    }

    protected function exportFile(string $locale, string $key, string $path): void
    {
        if (! file_exists($path)) {
            $this->warn("   • Skipping $key.php (file not found)");

            return;
        }

        try {
            /** @var array<string, mixed> $array */
            $array = File::getRequire($path);
        } catch (Throwable) {
            $this->warn("   • Skipping $key.php (error loading file)");

            return;
        }

        $json = $this->transformArray($array);
        $outDir = base_path("public/locales/$locale/".dirname($key));
        $outPath = "$outDir/".basename($key).'.json';

        File::ensureDirectoryExists($outDir);
        File::put($outPath, (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_SLASHES));

        $this->line("   • $key.json");
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function transformArray(array $input): array
    {
        $output = [];

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                /** @var array<string,mixed> $nested */
                $nested = $value;
                $output[$key] = $this->transformArray($nested);

                continue;
            }

            if (! is_string($value)) {
                // skip non-string values
                $output[$key] = $value;

                continue;
            }

            $str = $value;

            // split on unescaped pipes, never false now
            /** @var string[] $parts */
            $parts = preg_split(
                '/\|(?=(?:[^{]*\{[^}]*})*[^}]*$)/u',
                $str
            ) ?: [];

            if (count($parts) === 1) {
                // Case 1: no pipes
                $output[$key] = $this->transformSingle($str);
            } elseif ($this->isSimplePlural($parts)) {
                // Case 2: exactly two parts, neither in choice syntax
                [$one, $other] = array_map(fn (string $p): string => trim($p), $parts);
                $output["{$key}_one"] = $this->transformSingle($one);
                $output["{$key}_other"] = $this->transformSingle($other);
            } else {
                // Case 3: true trans_choice syntax
                [$zero, $one, $other] = $this->parseChoiceVariants($parts);
                $output["{$key}_zero"] = $zero;
                $output["{$key}_one"] = $one;
                $output["{$key}_other"] = $other;
            }
        }

        return $output;
    }

    protected function transformSingle(string $value): string
    {
        return $this->fixPlaceholders($value);
    }

    /**
     * @param  string[]  $parts
     */
    protected function isSimplePlural(array $parts): bool
    {
        return count($parts) === 2
            && ! preg_match('/^\s*[{\[]\s*\d/', trim($parts[0]))
            && ! preg_match('/^\s*[{\[]\s*\d/', trim($parts[1]));
    }

    /**
     * Walk every segment and slot into [zero, one, other].
     *
     * @param  string[]  $parts
     * @return string[] [zero, one, other]
     */
    protected function parseChoiceVariants(array $parts): array
    {
        $vars = ['zero' => '', 'one' => '', 'other' => ''];

        foreach ($parts as $segment) {
            $seg = trim($segment);

            if (preg_match('/^\{\s*0\s*}\s*(.+)$/u', $seg, $m)) {
                $vars['zero'] = $this->fixPlaceholders($m[1]);
            } elseif (preg_match('/^\{\s*1\s*}\s*(.+)$/u', $seg, $m)) {
                $vars['one'] = $this->fixPlaceholders($m[1]);
            } elseif (preg_match('/^\[\s*2\s*,\s*\*\s*]\s*(.+)$/u', $seg, $m)) {
                $vars['other'] .= ' '.$this->fixPlaceholders($m[1]);
            } else {
                $vars['other'] .= ' '.$this->fixPlaceholders($seg);
            }
        }

        return [
            trim($vars['zero']),
            trim($vars['one']),
            trim($vars['other']),
        ];
    }

    /**
     * Sanitize HTML tags to only allow the tag name, removing attributes.
     */
    protected function sanitizeHtmlTags(string $line): string
    {
        return preg_replace('/<(\w+)(?:\s+[^>]*)?>/', '<$1>', $line) ?? '';
    }

    /**
     * Replace `:var`, `:Var`, `:VAR` → i18next interpolation:
     *  - `:foo` → {{foo}}
     *  - `:Foo` → {{foo, capitalize}}
     *  - `:FOO` → {{foo, uppercase}}
     */
    protected function fixPlaceholders(string $line): string
    {
        $line = $this->sanitizeHtmlTags($line);

        return (string) preg_replace_callback(
            '/:([A-Za-z0-9_]+)/',
            function (array $m): string {
                $raw = $m[1];
                $lower = strtolower($raw);

                if ($raw === strtoupper($raw)) {
                    return "{{{$lower}, uppercase}}";
                }
                if ($raw === ucfirst($lower)) {
                    return "{{{$lower}, capitalize}}";
                }

                return "{{{$lower}}}";
            },
            $line
        );
    }
}
