<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Output path
    |--------------------------------------------------------------------------
    |
    | Where the i18next JSON is written: {output_path}/{locale}/{namespace}.json,
    | plus {output_path}/versions.json for cache-busting.
    |
    */

    'output_path' => public_path('locales'),

    /*
    |--------------------------------------------------------------------------
    | Strip HTML attributes
    |--------------------------------------------------------------------------
    |
    | Reduce `<b class="x">` to `<b>` so strings work with react-i18next's
    | <Trans> component, which maps bare tag names to components.
    |
    */

    'strip_html_attributes' => true,

    /*
    |--------------------------------------------------------------------------
    | TypeScript declarations
    |--------------------------------------------------------------------------
    |
    | When `path` is set (or `--types=` is passed), a .d.ts describing every
    | namespace is written there, built from `locale` (the app's fallback
    | locale when null). Point i18next's CustomTypeOptions at it to have
    | missing keys and interpolation options caught by the compiler.
    |
    */

    'types' => [
        'path' => null,
        'locale' => null,
    ],

];
