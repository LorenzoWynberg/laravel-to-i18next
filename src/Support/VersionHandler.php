<?php

namespace OznerOmali\LaravelToI18nextLangParser\Support;

use Illuminate\Support\Facades\File;

class VersionHandler
{
    protected string $versionFile;

    public function __construct()
    {
        $this->versionFile = base_path('public/locales/versions.json');
    }

    /**
     * Update the hash and last_updated for a given locale if content changed.
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function update(string $locale): void
    {
        $data = $this->load();

        // Compute hash for all files in the locale dir
        $hash = $this->computeHash($locale);

        if (! isset($data[$locale]) || $data[$locale]['hash'] !== $hash) {
            $data[$locale] = [
                'hash' => $hash,
                'last_updated' => now()->toISOString(),
            ];
        }

        File::ensureDirectoryExists(dirname($this->versionFile));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File::put($this->versionFile, $json ?: '');
    }

    /**
     * Load the current versions.json or return an empty array.
     *
     * @return array<string, array{hash: string, last_updated: string}>
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function load(): array
    {
        if (! file_exists($this->versionFile)) {
            return [];
        }

        /** @var array<string, array{hash: string, last_updated: string}>|null $data */
        $data = json_decode(File::get($this->versionFile), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Compute a combined hash of all JSON files in a locale directory.
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function computeHash(string $locale): string
    {
        $dir = base_path("public/locales/$locale");
        if (! File::isDirectory($dir)) {
            return '';
        }

        $hashCtx = hash_init('sha1');
        $files = File::allFiles($dir);

        foreach ($files as $file) {
            hash_update($hashCtx, File::get($file->getPathname()));
        }

        return hash_final($hashCtx);
    }
}
