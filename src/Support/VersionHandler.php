<?php

namespace OznerOmali\LaravelToI18nextLangParser\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;

class VersionHandler
{
    public function __construct(private readonly string $outputPath) {}

    /**
     * Update the hash and last_updated for a given locale if its content changed.
     *
     * @throws FileNotFoundException
     */
    public function update(string $locale): void
    {
        $data = $this->load();
        $hash = $this->computeHash($locale);

        if (($data[$locale]['hash'] ?? null) !== $hash) {
            $data[$locale] = [
                'hash' => $hash,
                'last_updated' => now()->toISOString(),
            ];
        }

        ksort($data);

        File::ensureDirectoryExists($this->outputPath);
        File::put($this->versionFile(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function versionFile(): string
    {
        return $this->outputPath.'/versions.json';
    }

    /**
     * Load the current versions.json or return an empty array.
     *
     * @return array<string, array{hash: string, last_updated: string}>
     *
     * @throws FileNotFoundException
     */
    private function load(): array
    {
        if (! File::exists($this->versionFile())) {
            return [];
        }

        /** @var array<string, array{hash: string, last_updated: string}>|null $data */
        $data = json_decode(File::get($this->versionFile()), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Hash every JSON file of a locale, names included, so renaming a namespace changes it too.
     *
     * @throws FileNotFoundException
     */
    private function computeHash(string $locale): string
    {
        $dir = "{$this->outputPath}/{$locale}";
        $context = hash_init('sha1');

        // allFiles() sorts by name, so the hash does not depend on the filesystem's order.
        foreach (File::isDirectory($dir) ? File::allFiles($dir) : [] as $file) {
            hash_update($context, $file->getRelativePathname()."\0".File::get($file->getPathname())."\0");
        }

        return hash_final($context);
    }
}
