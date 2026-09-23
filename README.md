# Laravel → i18next Lang Parser

This package parses your <a href="https://laravel.com/docs/localization" target="_blank" rel="noopener noreferrer">Laravel translation</a> files into <a href="https://www.i18next.com/" target="_blank" rel="noopener noreferrer">i18next</a>-compatible JSON, converting plural forms and placeholders into a format ready for dynamic translation usage in the frontend.

---

## 🎬 Features

- Reads every `lang/{locale}/**/*.php` file (`lang/vendor` is skipped)
- **Plurals that match `trans_choice` exactly.** Every plural is resolved by Laravel's own `MessageSelector` for each CLDR category i18next can ask for in that locale, so `a|b`, `{0}|{1}|[2,*]`, ranges and three-form strings convert to what Laravel would print — including `_many` (French, Spanish, Italian…) and `_few` (Russian, Polish…). Anything i18next cannot express is reported, never guessed silently.
- Converts placeholders the way Laravel matches them:
    - `:foo` → `{{foo}}`, `:dueDate` → `{{dueDate}}`
    - `:Foo` → `{{foo, capitalize}}`
    - `:FOO` → `{{foo, uppercase}}`
    - `10:30` and `https://…` stay literal text
- Strips attributes from HTML tags for `<Trans>` (configurable)
- Writes to `public/locales/{locale}/*.json` (configurable), plus `versions.json` with a content hash per locale for cache-busting
- Optional **TypeScript declarations**, so a missing key, a key that names a subtree, or a wrong interpolation value is a compile error in the frontend
- `--strict` for CI, `--prune` for renamed or deleted files

**Requirements:** PHP 8.4, Laravel 12 or 13, and `ext-intl` (for the CLDR plural rules).

---

## 📦 Installation

```
composer require ozner-omali/laravel-to-i18next
```

Optionally publish the config:

```
php artisan vendor:publish --tag=laravel-to-i18next-config
```

---

## 🔧 Usage

```bash
php artisan lang:to-i18next                 # export every locale
php artisan lang:to-i18next es              # export one locale
php artisan lang:to-i18next --prune         # also delete JSON whose PHP source is gone
php artisan lang:to-i18next --strict        # exit 1 if anything could not be converted exactly
php artisan lang:to-i18next --types=resources/js/types/i18next.d.ts   # also write TypeScript types
```

This will generate: \
• Translation files under: /public/locales/{locale}/*.json \
• A version tracking file under: /public/locales/versions.json

`public/locales` is build output: gitignore it and run the command in your deploy script (e.g. with `--strict --prune`).

### Configuration

```php
// config/laravel-to-i18next.php
return [
    'output_path' => public_path('locales'),
    'strip_html_attributes' => true,
    'types' => [
        'path' => null,    // e.g. resource_path('js/types/i18next.d.ts')
        'locale' => null,  // defaults to app.fallback_locale
    ],
];
```

### Example versions.json file
```json
{
    "en": {
        "hash": "a1b2c3d4e5f6g7h8i9j0",
        "last_updated": "2025-06-01T12:00:00Z"
    },
    "es": {
        "hash": "0j9i8h7g6f5e4d3c2b1a",
        "last_updated": "2025-06-01T12:30:00Z"
    }
}
```

### Example Laravel Translations:
// resources/lang/en/messages.php
```php
return [
    'success' => [
        'created' => '{0} No :resource created.|{1} :Resource created successfully.|[2,*] Many :resource created successfully.'
    ],
];
```
// resources/lang/en/models.php
```php
return [
    'user' => 'user|users',
    'address' => 'address|addresses',
];
```
### Generated i18next JSON:
// resources/locales/en/messages.json
```json
{
    "success": {
        "created_zero": "No {{resource}} created.",
        "created_one": "{{resource, capitalize}} created successfully.",
        "created_other": "Many {{resource}} created successfully."
    }
}
```
// resources/lang/en/models.json
```json
{
    "user_one": "user",
    "user_other": "users",
    "address_one": "address",
    "address_other": "addresses"
}
```

### How plurals are converted

i18next picks a plural form with `Intl.PluralRules`, so the set of suffixes depends on the language: English has `_one`/`_other`, French adds `_many` (for 1,000,000+), Russian has `_one`/`_few`/`_many`/`_other`. For each of those categories the package asks Laravel what `trans_choice` would print for counts in it, and emits that text. `_zero` is only emitted when Laravel prints something specific for 0 — i18next uses `_zero` for a count of 0 in every language.

When a Laravel string makes a distinction i18next cannot (e.g. `[1,5] a few|[6,*] many`, where 2 and 6 are both `_other` in English), the command prints a warning naming the key and uses the text for the smallest count. `--strict` turns those warnings into a failing exit code.

A string with a `|` is always treated as a plural. If you need a literal pipe, keep that string out of the exported files.

---

## 🟦 TypeScript

Pass `--types=<path>` (or set `types.path`) to write a declaration of every namespace, with each value as a string literal:

```ts
// src/types/i18next.d.ts — generated
export interface Resources {
  "notifications": {
    "quote_ready_for_order": "A quote is ready for order #{{orderId}}";
  };
  // …
}
```

Register it once and `t()` is checked by the compiler:

```ts
import type Resources from './types/i18next';

declare module 'i18next' {
  interface CustomTypeOptions {
    defaultNS: 'common';
    resources: Resources;
  }
}

t('notifications:quote_ready_for_order', { orderId });  // ✅
t('notifications:quote_ready_for_order', { orderid });  // ❌ wrong interpolation value
t('notifications:nope');                                // ❌ missing key
t('validation:invalid');                                // ❌ names a subtree, not a string
```

Plural keys are addressed by their stem (`t('models:user', { count })`). i18next's types do not require `count` on a plural stem, so that one mistake still needs a runtime check.

---

## ⚙️ Front-end Integration


### 1. Installation
```bash
npm install i18next react-i18next i18next-http-backend react-native-localize
```

### 2. useLangStore.ts (Zustand store)
Define a store with:
 - hydrated: boolean — whether storage has finished hydration 
 - lang: string — current language 
 - versions: Record<string, { hash: string; last_updated: string }> — the translation version hashes
 - getVersion(lang) to fetch hash or fallback to "latest"

```typescript
import { create } from 'zustand';
import { immer } from 'zustand/middleware/immer';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';

type VersionsMap = Record<string, { hash: string; last_updated: string }>;

type LangStore = {
  hydrated: boolean;

  lang: string;
  setLang: (lang: string) => void;

  versions: VersionsMap;
  getVersion: (lang: string) => string;
  setVersions: (versions: VersionsMap) => void;
};

export const useLangStore = create<LangStore>()(
  persist(
    immer((set, get) => ({
      // Hydration
      hydrated: false,

      // Language
      lang: 'en',
      setLang: lang =>
        set(state => {
          state.lang = lang;
        }),

      // Versions
      versions: {},
      getVersion: lang => {
        return get().versions[lang]?.hash ?? 'latest';
      },
      setVersions: versions =>
        set(state => {
          state.versions = { ...versions };
        }),
    })),
    {
      name: 'lang-storage',
      storage: createJSONStorage(() => AsyncStorage),
      onRehydrateStorage: () => {
        return (_state, error) => {
          if (!error) {
            useLangStore.setState({ hydrated: true });
          }
        };
      },
      partialize: (state): Pick<LangStore, 'lang' | 'versions'> => ({
        lang: state.lang,
        versions: state.versions,
      }),
    },
  ),
);
```
### 3. useFetchVersions.ts (Hook to sync version.json)
```typescript
import { useEffect } from 'react';
import { BACKEND_URL } from '@env';
import { useLangStore } from '@/stores/useLangStore';
import i18next from '@/config/i18next';

export const useFetchVersions = (hydrated: Boolean) => {
  const setVersions = useLangStore.getState().setVersions;
  const getVersions = useLangStore.getState().versions;

  useEffect(() => {
    if (!hydrated) return;
    const fetchVersions = async () => {
      try {
        // Append timestamp to bust all caches
        const timestamp = Date.now();
        const url = `${BACKEND_URL}/locales/versions.json?ts=${timestamp}`;

        const res = await fetch(url);

        if (!res.ok)
          throw new Error(`Failed to fetch versions (${res.status})`);

        const data = await res.json();

        const previous = getVersions;
        const changed = JSON.stringify(previous) !== JSON.stringify(data);

        if (changed) {
          console.log(
            '[i18n - useFetchVersions] VERSIONS UPDATED, reloading translations...',
          );
          setVersions(data);

          const currentLng = i18next.language || 'en';
          const namespaces = i18next.options.ns as string[];

          await i18next.reloadResources([currentLng], namespaces);
        } else {
          console.log(
            '[i18n - useFetchVersions] VERSIONS UNCHANGED, using cached translations.',
          );
        }
      } catch (e) {
        console.warn(
          '[i18n - useFetchVersions] Failed to fetch versions.json:',
          e,
        );
      }
    };

    fetchVersions();
  }, [hydrated]);
};
```

### 4. config/i18next.ts (Initialization)
```typescript jsx 
import i18next from 'i18next';
import { BACKEND_URL } from '@env';
import { initReactI18next } from 'react-i18next';
import { useLangStore } from '@/stores/useLangStore';
import HttpApi, { HttpBackendOptions } from 'i18next-http-backend';

const fallbackLng = 'en';
const namespaces = [
    'addresses',
    'auth',
    'cache',
    'common',
    'http',
    'models',
    'pagination',
    'passwords',
    'resource',
    'validation',
];

export const initI18n = async () => {
    const store = useLangStore.getState();
    const initialLang = store.lang;

    return i18next
        .use(HttpApi)
        .use(initReactI18next)
        .init<HttpBackendOptions>({
            lng: initialLang,
            fallbackLng,
            ns: namespaces,
            defaultNS: 'common',
            backend: {
                loadPath: (lngs: string[], namespaces: string[]): string => {
                    const lng = lngs[0];
                    const ns = namespaces[0];
                    const version = useLangStore.getState().getVersion(lng);
                    console.log(
                        `[i18n] Loading translations for ${lng}/${ns} (version: ${version})`,
                    );
                    return `${BACKEND_URL}/locales/${lng}/${ns}.json?v=${version}`;
                },
            },
            interpolation: {
                escapeValue: false,
            },
            react: {
                useSuspense: false,
            },
        });

    // i18next 26 removed `interpolation.format`; register the formatters the
    // generated `{{x, capitalize}}` / `{{x, uppercase}}` interpolations use.
    i18next.services.formatter?.add('capitalize', value =>
        typeof value === 'string' ? value.charAt(0).toUpperCase() + value.slice(1) : String(value),
    );
    i18next.services.formatter?.add('uppercase', value =>
        typeof value === 'string' ? value.toUpperCase() : String(value),
    );

    return i18next;
};

export default i18next;
```
### 5. App.tsx (Entry point)
```typescript jsx
import i18next from './config/i18next';
import { initI18n } from './config/i18next';
import { useEffect, useState } from 'react';
import { I18nextProvider } from 'react-i18next';
import { useLangStore } from './stores/useLangStore';
import { ThemeProvider } from './theme/ThemeProvider';
import { View, ActivityIndicator } from 'react-native';
import AppNavigation from './navigations/AppNavigation';
import { useFetchVersions } from './hooks/useFetchVersions';
import { SafeAreaProvider } from 'react-native-safe-area-context';

export default function App() {
  const langStoreHydrated = useLangStore(state => state.hydrated);
  const [i18nReady, setI18nReady] = useState(false);

  // Fetch versions only after hydration
  useFetchVersions(langStoreHydrated);

  useEffect(() => {
    if (langStoreHydrated) {
      initI18n().then(() => setI18nReady(true));
    }
  }, [langStoreHydrated]);

  if (!langStoreHydrated || !i18nReady) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  return (
    <I18nextProvider i18n={i18next}>
      <ThemeProvider>
        <SafeAreaProvider>
          <AppNavigation />
        </SafeAreaProvider>
      </ThemeProvider>
    </I18nextProvider>
  );
}
```
### 6. Basic Example Usage
```javascript
// models.user: 'user|users'
// messages.success.created: '{0} No :resource created.|{1} :Resource created successfully.|[2,*] Many :resource created successfully.'

const created = (count) =>
    i18next.t('messages:success.created', {
        count,
        resource: i18next.t('models:user', { count }),
    });

created(0); // → "No users created."
created(1); // → "User created successfully."
created(5); // → "Many users created successfully."
```
### 7. Trans Component Example
#### A. Laravel translation: resources/lang/en/{file}.php
```php
'apples' => '{0} Hello <b style="background-color: #0ea5e9">:name</b>, <br/>there are none
            |{1} Hello <b class="highlight">:name</b>, there is one apple
            |[2,*] Hello <b id="special-name">:name</b>, there are :count apples',
```

#### B. Generated i18next JSON: public/locales/en/{file}.json
```JSON
{
  "apples_zero": "Hello <b>{{name}}</b>, <br/>there are none",
  "apples_one": "Hello <b>{{name}}</b>, there is one apple",
  "apples_other": "Hello <b>{{name}}</b>, there are {{count}} apples"
}
```

#### C. /components/i18Next/TransComponents.tsx
```typescript jsx
import React from 'react';
import { Text } from 'react-native';

export const transComponents = {
    // Bold text
    b: <Text style={{ fontWeight: 'bold' }} />,
    bold: <Text style={{ fontWeight: 'bold' }} />,

    // Italic text
    i: <Text style={{ fontStyle: 'italic' }} />,
    italic: <Text style={{ fontStyle: 'italic' }} />,

    // Line breaks
    br: <Text>{'\n'}</Text>,

    // Span (generic inline container)
    span: <Text />,
};

```
            
#### D. Usage in component
```typescript jsx
<Text>
    <Trans
        i18nKey="models:apples"
        values={{ name: 'Ozner', count: 0 }}
        components={transComponents}
    />
</Text>
```
#### E. Output
<pre>
Hello <b>Ozner</b>, <br />there are none
</pre>

---

## ✅ Notes

- Only PHP translation files are exported. Laravel's `lang/{locale}.json` files are keyed by whole sentences, which collide with i18next's `.` and `:` separators.
- `:KEY` in uppercase cannot reveal the original casing of a camelCase key, so it becomes `{{key, uppercase}}`; pass the lowercase name.
- Flat keys that contain a dot (`'login.title' => …`) are kept flat; i18next still resolves them.

---

## 📖 Changelog
<pre>
See <a href="https://github.com/LorenzoWynberg/laravel-to-i18next/blob/main/CHANGELOG.md" target="_blank" rel="noopener noreferrer">CHANGELOG.md</a> for release notes and breaking changes.
</pre>

## 🤝 Contributing
<pre>
Contributions, issues, and feature requests are welcome!

🔗 <a href="https://github.com/LorenzoWynberg/laravel-to-i18next/issues" target="_blank" rel="noopener noreferrer">Report Issues</a>
🔗 <a href="https://github.com/LorenzoWynberg/laravel-to-i18next/pulls" target="_blank" rel="noopener noreferrer">Submit Pull Requests</a>
</pre>

## 🔑 License
<pre>
🔑 <a href="https://raw.githubusercontent.com/LorenzoWynberg/laravel-to-i18next/main/LICENSE.md" target="_blank" rel="noopener noreferrer">MIT License</a> © Lorenzo Wynberg / Ozner Omali
</pre>

## 🎵 Like my code? You'll love my music!

<ul>
  <li><a href="https://music.apple.com/us/album/the-kitty-cat-crew/1796753922" target="_blank" rel="noopener noreferrer">Apple Music</a></li>
  <li><a href="https://open.spotify.com/album/0uTRS5Z5Qebgi7BavwGlpm" target="_blank" rel="noopener noreferrer">Spotify</a></li>
</ul>

