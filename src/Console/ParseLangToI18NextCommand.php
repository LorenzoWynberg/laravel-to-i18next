<?php

namespace OznerOmali\LaravelToI18nextLangParser\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use OznerOmali\LaravelToI18nextLangParser\Support\Converter;
use OznerOmali\LaravelToI18nextLangParser\Support\PluralRules;
use OznerOmali\LaravelToI18nextLangParser\Support\TypeScriptGenerator;
use OznerOmali\LaravelToI18nextLangParser\Support\VersionHandler;
use Throwable;

class ParseLangToI18NextCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lang:to-i18next
        {locale? : Export only this locale}
        {--prune : Delete JSON files in the output that no longer have a source file}
        {--strict : Exit with a failure when any string cannot be converted exactly}
        {--types= : Also write a TypeScript declaration of every namespace to this path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse Laravel language files into i18next-compatible JSON';

    private const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private Converter $converter;

    private string $outputPath;

    private int $problems = 0;

    /**
     * @throws FileNotFoundException
     */
    public function handle(): int
    {
        $langPath = lang_path();
        if (! File::isDirectory($langPath)) {
            $this->error("lang/ directory not found at: {$langPath}");

            return self::FAILURE;
        }

        $this->outputPath = rtrim($this->configString('output_path') ?? public_path('locales'), '/');
        $this->converter = new Converter(
            new PluralRules,
            stripHtmlAttributes: (bool) config('laravel-to-i18next.strip_html_attributes', true),
        );

        // `lang/vendor` holds package translations (`lang/vendor/{package}/{locale}`), not a locale.
        $allLocales = [];
        foreach (File::directories($langPath) as $dir) {
            if (is_string($dir) && basename($dir) !== 'vendor') {
                $allLocales[] = basename($dir);
            }
        }

        /** @var ?string $requested */
        $requested = $this->argument('locale');

        if ($requested !== null && ! in_array($requested, $allLocales, true)) {
            $this->error("Locale [{$requested}] does not exist in {$langPath}");

            return self::FAILURE;
        }

        $versions = new VersionHandler($this->outputPath);
        $converted = [];

        foreach ($requested !== null ? [$requested] : $allLocales as $locale) {
            $this->info("→ Exporting locale: {$locale}");
            $converted[$locale] = $this->exportLocale($locale, "{$langPath}/{$locale}");
            $versions->update($locale);
        }

        $this->writeTypes($converted, $langPath);

        if ($this->problems > 0) {
            $this->warn("⚠ {$this->problems} string(s) could not be converted exactly — see the warnings above.");

            if ($this->option('strict')) {
                return self::FAILURE;
            }
        }

        $this->info('✅ All translations exported!');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<array-key, mixed>> namespace => converted lines
     */
    private function exportLocale(string $locale, string $sourceDir, bool $write = true): array
    {
        $namespaces = [];
        $written = [];

        foreach (File::allFiles($sourceDir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // 'validation.php' or 'nested/foo.php' → 'validation' or 'nested/foo'
            $namespace = substr($file->getRelativePathname(), 0, -4);
            $lines = $this->load($file->getPathname(), $namespace);

            if ($lines === null) {
                continue;
            }

            $namespaces[$namespace] = $this->converter->convert($lines, $locale);
            $this->report($locale, $namespace);

            if ($write) {
                $outPath = "{$this->outputPath}/{$locale}/{$namespace}.json";
                File::ensureDirectoryExists(dirname($outPath));
                File::put($outPath, json_encode($namespaces[$namespace] ?: new \stdClass, self::JSON_FLAGS)."\n");
                $written[] = realpath($outPath);
                $this->line("   • {$namespace}.json");
            }
        }

        if ($write && $this->option('prune')) {
            $this->prune("{$this->outputPath}/{$locale}", $written);
        }

        return $namespaces;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function load(string $path, string $namespace): ?array
    {
        try {
            $lines = File::getRequire($path);
        } catch (Throwable $e) {
            $this->warn("   • Skipping {$namespace}.php (error loading file: {$e->getMessage()})");
            $this->problems++;

            return null;
        }

        if (! is_array($lines)) {
            $this->warn("   • Skipping {$namespace}.php (it does not return an array)");
            $this->problems++;

            return null;
        }

        return $lines;
    }

    private function report(string $locale, string $namespace): void
    {
        foreach ($this->converter->warnings() as $warning) {
            $this->warn("   ⚠ [{$locale}] {$namespace}.{$warning}");
            $this->problems++;
        }
    }

    /**
     * @param  list<string|false>  $keep
     */
    private function prune(string $dir, array $keep): void
    {
        if (! File::isDirectory($dir)) {
            return;
        }

        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() === 'json' && ! in_array($file->getRealPath(), $keep, true)) {
                File::delete($file->getPathname());
                $this->line("   • pruned {$file->getRelativePathname()}");
            }
        }
    }

    /**
     * @param  array<string, array<string, array<array-key, mixed>>>  $converted
     */
    private function writeTypes(array $converted, string $langPath): void
    {
        $path = $this->configString('types.path', 'types');
        if ($path === null) {
            return;
        }

        $fallback = config('app.fallback_locale');
        $locale = $this->configString('types.locale') ?? (is_string($fallback) ? $fallback : 'en');

        if (! isset($converted[$locale])) {
            if (! File::isDirectory("{$langPath}/{$locale}")) {
                $this->error("Cannot write types: locale [{$locale}] does not exist in {$langPath}");
                $this->problems++;

                return;
            }

            $converted[$locale] = $this->exportLocale($locale, "{$langPath}/{$locale}", write: false);
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, (new TypeScriptGenerator)->generate($converted[$locale], $locale));
        $this->info("→ Types written to {$path}");
    }

    private function configString(string $key, ?string $option = null): ?string
    {
        $value = ($option !== null ? $this->option($option) : null) ?? config("laravel-to-i18next.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
