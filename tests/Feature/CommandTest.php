<?php

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/laravel-to-i18next-'.bin2hex(random_bytes(4));
    $this->lang = "{$this->root}/lang";
    $this->out = "{$this->root}/locales";

    File::ensureDirectoryExists("{$this->lang}/en");
    File::ensureDirectoryExists("{$this->lang}/es");
    File::put("{$this->lang}/en/models.php", "<?php return ['user' => 'user|users', 'greeting' => 'Hi :firstName'];");
    File::put("{$this->lang}/es/models.php", "<?php return ['user' => 'usuario|usuarios'];");

    $this->app->useLangPath($this->lang);
    config(['laravel-to-i18next.output_path' => $this->out]);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

function readJson(string $path): mixed
{
    return json_decode((string) file_get_contents($path), true);
}

it('exports every locale and records versions', function (): void {
    $this->artisan('lang:to-i18next')->assertSuccessful();

    expect(readJson("{$this->out}/en/models.json"))->toBe([
        'user_one' => 'user',
        'user_other' => 'users',
        'greeting' => 'Hi {{firstName}}',
    ])
        ->and(readJson("{$this->out}/es/models.json"))->toBe([
            'user_one' => 'usuario',
            'user_many' => 'usuarios',
            'user_other' => 'usuarios',
        ])
        ->and(array_keys(readJson("{$this->out}/versions.json")))->toBe(['en', 'es']);
});

it('exports a single locale', function (): void {
    $this->artisan('lang:to-i18next', ['locale' => 'es'])->assertSuccessful();

    expect(File::exists("{$this->out}/es/models.json"))->toBeTrue()
        ->and(File::exists("{$this->out}/en"))->toBeFalse();
});

it('fails for an unknown locale', function (): void {
    $this->artisan('lang:to-i18next', ['locale' => 'xx'])
        ->expectsOutputToContain('Locale [xx] does not exist')
        ->assertFailed();
});

it('fails without a lang directory', function (): void {
    $this->app->useLangPath("{$this->root}/missing");

    $this->artisan('lang:to-i18next')->expectsOutputToContain('lang/ directory not found')->assertFailed();
});

it('does not treat lang/vendor as a locale', function (): void {
    File::ensureDirectoryExists("{$this->lang}/vendor/package/en");
    File::put("{$this->lang}/vendor/package/en/x.php", "<?php return ['a' => 'b'];");

    $this->artisan('lang:to-i18next')->assertSuccessful();

    expect(File::exists("{$this->out}/vendor"))->toBeFalse();
});

it('exports nested files, ignores non-PHP files and writes {} for an empty file', function (): void {
    File::ensureDirectoryExists("{$this->lang}/en/admin");
    File::put("{$this->lang}/en/admin/menu.php", "<?php return ['home' => 'Home'];");
    File::put("{$this->lang}/en/empty.php", '<?php return [];');
    File::put("{$this->lang}/en/notes.txt", 'not a translation');

    $this->artisan('lang:to-i18next', ['locale' => 'en'])->assertSuccessful();

    expect(readJson("{$this->out}/en/admin/menu.json"))->toBe(['home' => 'Home'])
        ->and(File::get("{$this->out}/en/empty.json"))->toBe("{}\n")
        ->and(File::exists("{$this->out}/en/notes.json"))->toBeFalse();
});

it('skips files that fail to load or return no array, and --strict fails on them', function (string $contents, string $message): void {
    File::put("{$this->lang}/en/broken.php", $contents);

    $this->artisan('lang:to-i18next', ['locale' => 'en'])->expectsOutputToContain($message)->assertSuccessful();
    $this->artisan('lang:to-i18next', ['locale' => 'en', '--strict' => true])->assertFailed();

    expect(File::exists("{$this->out}/en/broken.json"))->toBeFalse();
})->with([
    'throws' => ['<?php throw new RuntimeException("boom");', 'Skipping broken.php (error loading file: boom)'],
    'not an array' => ['<?php return "nope";', 'Skipping broken.php (it does not return an array)'],
]);

it('reports lossy plurals, and fails on them with --strict', function (): void {
    File::put("{$this->lang}/en/counts.php", "<?php return ['items' => '{0} none|[1,5] few|[6,*] lots'];");

    $this->artisan('lang:to-i18next', ['locale' => 'en'])
        ->expectsOutputToContain('[en] counts.items: Laravel prints different text for 2 and 6')
        ->expectsOutputToContain('1 string(s) could not be converted exactly')
        ->assertSuccessful();

    $this->artisan('lang:to-i18next', ['locale' => 'en', '--strict' => true])->assertFailed();
});

it('prunes JSON whose source file is gone, only with --prune', function (): void {
    $this->artisan('lang:to-i18next')->assertSuccessful();
    File::delete("{$this->lang}/en/models.php");
    File::put("{$this->lang}/en/common.php", "<?php return ['ok' => 'OK'];");

    $this->artisan('lang:to-i18next', ['locale' => 'en'])->assertSuccessful();
    expect(File::exists("{$this->out}/en/models.json"))->toBeTrue();

    $this->artisan('lang:to-i18next', ['locale' => 'en', '--prune' => true])
        ->expectsOutputToContain('pruned models.json')
        ->assertSuccessful();

    expect(File::exists("{$this->out}/en/models.json"))->toBeFalse()
        ->and(File::exists("{$this->out}/en/common.json"))->toBeTrue()
        ->and(File::exists("{$this->out}/es/models.json"))->toBeTrue()
        ->and(File::exists("{$this->out}/versions.json"))->toBeTrue();
});

it('only bumps a version when the content changes', function (): void {
    $this->artisan('lang:to-i18next')->assertSuccessful();
    $before = readJson("{$this->out}/versions.json");

    $this->travel(5)->minutes();
    $this->artisan('lang:to-i18next')->assertSuccessful();
    expect(readJson("{$this->out}/versions.json"))->toBe($before);

    File::put("{$this->lang}/en/models.php", "<?php return ['user' => 'member|members'];");
    $this->artisan('lang:to-i18next')->assertSuccessful();
    $after = readJson("{$this->out}/versions.json");

    expect($after['en']['hash'])->not->toBe($before['en']['hash'])
        ->and($after['en']['last_updated'])->not->toBe($before['en']['last_updated'])
        ->and($after['es'])->toBe($before['es']);
});

it('changes the version when a namespace is renamed', function (): void {
    $this->artisan('lang:to-i18next', ['locale' => 'en'])->assertSuccessful();
    $before = readJson("{$this->out}/versions.json")['en']['hash'];

    File::move("{$this->lang}/en/models.php", "{$this->lang}/en/entities.php");
    $this->artisan('lang:to-i18next', ['locale' => 'en', '--prune' => true])->assertSuccessful();

    expect(readJson("{$this->out}/versions.json")['en']['hash'])->not->toBe($before);
});

it('versions a locale that has no files yet', function (): void {
    File::ensureDirectoryExists("{$this->lang}/fr");

    $this->artisan('lang:to-i18next', ['locale' => 'fr', '--prune' => true])->assertSuccessful();

    expect(readJson("{$this->out}/versions.json")['fr']['hash'])->toBe(sha1(''));
});

it('recovers from a corrupt versions.json', function (): void {
    File::ensureDirectoryExists($this->out);
    File::put("{$this->out}/versions.json", 'not json');

    $this->artisan('lang:to-i18next', ['locale' => 'en'])->assertSuccessful();

    expect(array_keys(readJson("{$this->out}/versions.json")))->toBe(['en']);
});

it('writes TypeScript declarations from the fallback locale', function (): void {
    $types = "{$this->root}/types/i18next.d.ts";

    $this->artisan('lang:to-i18next', ['--types' => $types])->assertSuccessful();

    expect(File::get($types))->toContain('"greeting": "Hi {{firstName}}";')
        ->toContain('from the [en] translations');
});

it('writes types from a configured locale that was not exported', function (): void {
    $types = "{$this->root}/i18next.d.ts";
    config(['laravel-to-i18next.types' => ['path' => $types, 'locale' => 'es']]);

    $this->artisan('lang:to-i18next', ['locale' => 'en'])->assertSuccessful();

    expect(File::get($types))->toContain('"user_many": "usuarios";')
        ->and(File::exists("{$this->out}/es"))->toBeFalse();
});

it('fails the types step for a missing types locale', function (): void {
    config(['laravel-to-i18next.types.locale' => 'xx']);

    $this->artisan('lang:to-i18next', ['--types' => "{$this->root}/t.d.ts", '--strict' => true])
        ->expectsOutputToContain('Cannot write types: locale [xx] does not exist')
        ->assertFailed();
});

it('keeps HTML attributes when configured to', function (): void {
    config(['laravel-to-i18next.strip_html_attributes' => false]);
    File::put("{$this->lang}/en/html.php", "<?php return ['link' => '<a href=\"/x\">x</a>'];");

    $this->artisan('lang:to-i18next', ['locale' => 'en'])->assertSuccessful();

    expect(readJson("{$this->out}/en/html.json"))->toBe(['link' => '<a href="/x">x</a>']);
});

it('publishes its config', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'laravel-to-i18next-config'])->assertSuccessful();

    expect(File::exists(config_path('laravel-to-i18next.php')))->toBeTrue();
    File::delete(config_path('laravel-to-i18next.php'));
});
