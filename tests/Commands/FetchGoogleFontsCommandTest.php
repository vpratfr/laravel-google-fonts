<?php

use Spatie\GoogleFonts\Commands\FetchGoogleFontsCommand;

it('can fetch configured fonts', function () {
    $this->disk()->assertMissing('952ee985ef/fonts.css');

    $this->artisan(FetchGoogleFontsCommand::class);

    $this->disk()->assertExists('952ee985ef/fonts.css');
});

it('will use the configured path when fetching fonts', function () {
    $path = 'my-path';

    config()->set('google-fonts.path', $path);

    $this->artisan(FetchGoogleFontsCommand::class);

    $this->disk()->assertExists("{$path}/952ee985ef/fonts.css");
});

it('skips already-fetched fonts when --new-fonts-only is passed', function () {
    $this->disk()->put('952ee985ef/fonts.css', 'cached-css');

    $this->artisan(FetchGoogleFontsCommand::class, ['--new-fonts-only' => true]);

    expect($this->disk()->get('952ee985ef/fonts.css'))->toBe('cached-css');
});
