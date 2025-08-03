<?php

use OznerOmali\LaravelToI18nextLangParser\Console\ParseLangToI18NextCommand;
use OznerOmali\LaravelToI18nextLangParser\Support\VersionHandler;

it('can instantiate', function () {
    $handler = new VersionHandler;
    $command = new ParseLangToI18NextCommand($handler);
    expect($command)->toBeObject();
});
