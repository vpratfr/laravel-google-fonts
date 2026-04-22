<?php

use Spatie\GoogleFonts\Commands\FetchGoogleFontsCommand;

it('can fetch configured fonts', function () {
    $identifier = substr(md5('inter'), 0, 10);
    $this->disk()->assertMissing("$identifier/fonts.css");

    $this->artisan(FetchGoogleFontsCommand::class);

    $this->disk()->assertExists("$identifier/fonts.css");
});

it('will use the configured path when fetching fonts', function () {
    $path = 'my-path';
    $identifier = substr(md5('inter'), 0, 10);

    config()->set('google-fonts.path', $path);

    $this->artisan(FetchGoogleFontsCommand::class);

    $this->disk()->assertExists("$path/$identifier/fonts.css");
});
