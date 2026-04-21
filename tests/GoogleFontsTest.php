<?php

use Illuminate\Support\Str;
use Spatie\GoogleFonts\Fonts;
use Spatie\GoogleFonts\GoogleFonts;

use function Spatie\Snapshots\assertMatchesFileSnapshot;
use function Spatie\Snapshots\assertMatchesHtmlSnapshot;

it('loads google fonts as string', function () {
    $fonts = app(GoogleFonts::class)->load('inter', forceDownload: true);

    $expectedFileName = '952ee985ef/fonts.css';

    $this->disk()->assertExists($expectedFileName);

    $fullCssPath = $this->disk()->path($expectedFileName);

    assertMatchesFileSnapshot($fullCssPath);

    $woff2FileCount = collect($this->disk()->allFiles())
        ->filter(fn (string $path) => Str::endsWith($path, '.woff2'))
        ->count();

    expect($woff2FileCount)->toBeGreaterThan(0);

    assertMatchesHtmlSnapshot((string) $fonts->link());
    assertMatchesHtmlSnapshot((string) $fonts->inline());

    $expectedUrl = $this->disk()->url($expectedFileName);
    expect($fonts->url())->toEqual($expectedUrl);
});

it('loads google fonts as array', function () {
    $fonts = app(GoogleFonts::class)->load(['font' => 'inter'], forceDownload: true);

    $expectedFileName = '952ee985ef/fonts.css';

    $this->disk()->assertExists($expectedFileName);

    $fullCssPath = $this->disk()->path($expectedFileName);

    assertMatchesFileSnapshot($fullCssPath);

    $woff2FileCount = collect($this->disk()->allFiles())
        ->filter(fn (string $path) => Str::endsWith($path, '.woff2'))
        ->count();

    expect($woff2FileCount)->toBeGreaterThan(0);

    assertMatchesHtmlSnapshot((string) $fonts->link());
    assertMatchesHtmlSnapshot((string) $fonts->inline());

    $expectedUrl = $this->disk()->url($expectedFileName);
    expect($fonts->url())->toEqual($expectedUrl);
});

it('falls back to google fonts with a string argument', function () {
    config()->set('google-fonts.fonts', ['cow' => 'moo']);
    config()->set('google-fonts.fallback', true);

    $fonts = app(GoogleFonts::class)->load('cow', forceDownload: true);

    $allFiles = $this->disk()->allFiles();

    expect($allFiles)->toHaveCount(0);

    $fallback = <<<HTML
            <link href="moo" rel="stylesheet" type="text/css">
        HTML;

    expect([
        (string) $fonts->link(),
        (string) $fonts->inline(),
    ])->each->toEqual($fallback)
        ->and($fonts->url())->toEqual('moo');
});

it('falls back to google fonts with a array argument', function () {
    config()->set('google-fonts.fonts', ['cow' => 'moo']);
    config()->set('google-fonts.fallback', true);

    $fonts = app(GoogleFonts::class)->load(['font' => 'cow'], forceDownload: true);

    $allFiles = $this->disk()->allFiles();

    expect($allFiles)->toHaveCount(0);

    $fallback = <<<HTML
            <link href="moo" rel="stylesheet" type="text/css">
        HTML;

    expect([
        (string) $fonts->link(),
        (string) $fonts->inline(),
    ])->each->toEqual($fallback)
        ->and($fonts->url())->toEqual('moo');
});

it('adds the nonce attribute when specified', function () {
    config()->set('google-fonts.fonts', ['cow' => 'moo']);
    config()->set('google-fonts.fallback', true);

    $fonts = app(GoogleFonts::class)->load(['font' => 'cow', 'nonce' => 'chicken'], forceDownload: true);

    expect([
        (string) $fonts->link(),
        (string) $fonts->inline(),
    ])->each->toContain('nonce="chicken"');
});

it('can generate a font path from font name', function () {
    config()->set('google-fonts.fonts', ['cow' => 'moo']);

    $googleFonts = app(GoogleFonts::class);

    $path = $googleFonts->fontPath('cow');

    $expectedIdentifier = substr(md5('moo'), 0, 10);

    expect($path)->toEqual($expectedIdentifier);
});

it('keeps font path deterministic', function () {
    config()->set('google-fonts.fonts', ['cow' => 'moo']);

    $googleFonts = app(GoogleFonts::class);

    expect($googleFonts->fontPath('cow'))
        ->toBe($googleFonts->fontPath('cow'));
});

it("throws RuntimeException when font doesn't exist", function () {
    $googleFonts = app(GoogleFonts::class);

    expect(fn () => $googleFonts->fontPath('cow'))
        ->toThrow(RuntimeException::class, "Font `cow` doesn't exist");
});

it('serves from local cache on second load without re-downloading', function () {
    $googleFonts = app(GoogleFonts::class);

    $googleFonts->load('inter', forceDownload: true);

    $filesAfterFirstLoad = $this->disk()->allFiles();

    $googleFonts->load('inter');

    expect($this->disk()->allFiles())->toEqual($filesAfterFirstLoad);
});

it('re-downloads fonts when forceDownload is true even if cached', function () {
    $googleFonts = app(GoogleFonts::class);

    $googleFonts->load('inter', forceDownload: true);

    $this->disk()->put('952ee985ef/fonts.css', 'stale content');

    $googleFonts->load('inter', forceDownload: true);

    expect($this->disk()->get('952ee985ef/fonts.css'))->not->toBe('stale content');
});

it('throws RuntimeException on load when font does not exist', function () {
    expect(fn () => app(GoogleFonts::class)->load('unknown'))
        ->toThrow(RuntimeException::class, "Font `unknown` doesn't exist");
});

it('throws RuntimeException on load when font does not exist and fallback is disabled', function () {
    config()->set('google-fonts.fallback', false);

    expect(fn () => app(GoogleFonts::class)->load('cow', forceDownload: true))
        ->toThrow(RuntimeException::class);
});

it('generates preload tags pointing to localized urls', function () {
    config()->set('google-fonts.preload', true);

    $fonts = app(GoogleFonts::class)->load('inter', forceDownload: true);

    $output = (string) $fonts->link();

    expect($output)
        ->toContain('<link rel="preload"')
        ->toContain('as="font"')
        ->toContain('type="font/woff2"')
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('fonts.gstatic.com');
});

it('loads multiple fonts as strings', function () {
    config()->set('google-fonts.fonts', [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
        'code' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',
    ]);

    $results = app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true);

    expect($results)
        ->toHaveCount(2)
        ->each->toBeInstanceOf(Fonts::class);
});

it('loads multiple fonts as arrays', function () {
    config()->set('google-fonts.fonts', [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
        'code' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',
    ]);

    $results = app(GoogleFonts::class)->loadMany(
        [['font' => 'inter'], ['font' => 'code']],
        forceDownload: true
    );

    expect($results)
        ->toHaveCount(2)
        ->each->toBeInstanceOf(Fonts::class);
});

it('persists css and woff2 files for each font', function () {
    config()->set('google-fonts.fonts', [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
        'code' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',
    ]);

    app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true);

    $interIdentifier = substr(md5('https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'), 0, 10);
    $codeIdentifier = substr(md5('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'), 0, 10);

    $this->disk()->assertExists("{$interIdentifier}/fonts.css");
    $this->disk()->assertExists("{$codeIdentifier}/fonts.css");

    $woff2Count = collect($this->disk()->allFiles())
        ->filter(fn (string $path) => Str::endsWith($path, '.woff2'))
        ->count();

    expect($woff2Count)->toBeGreaterThan(0);
});

it('preserves nonce per font in load many', function () {
    config()->set('google-fonts.fonts', [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
        'code' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',
    ]);

    [$inter, $code] = app(GoogleFonts::class)->loadMany(
        [
            ['font' => 'inter', 'nonce' => 'nonce-inter'],
            ['font' => 'code',  'nonce' => 'nonce-code'],
        ],
        forceDownload: true
    );

    expect((string)$inter->link())->toContain('nonce="nonce-inter"')
        ->and((string)$code->link())->toContain('nonce="nonce-code"');
});

it('returns localized urls in load many, not google fonts urls', function () {
    config()->set('google-fonts.fonts', [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
        'code' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',
    ]);

    $results = app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true);

    foreach ($results as $fonts) {
        expect($fonts->url())
            ->not->toContain('fonts.googleapis.com');
    }
});

it('serves many fonts from local cache without re-downloading', function () {
    config()->set('google-fonts.fonts', [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
        'code' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',
    ]);

    $googleFonts = app(GoogleFonts::class);

    $googleFonts->loadMany(['inter', 'code'], forceDownload: true);

    $filesAfterFirstLoad = $this->disk()->allFiles();

    $googleFonts->loadMany(['inter', 'code']);

    expect($this->disk()->allFiles())->toEqual($filesAfterFirstLoad);
});

it('only re-downloads missing fonts in load many', function () {
    config()->set('google-fonts.fonts', [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
        'code' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',
    ]);

    $googleFonts = app(GoogleFonts::class);

    $googleFonts->load('inter', forceDownload: true);

    $filesAfterInterLoad = $this->disk()->allFiles();

    $googleFonts->loadMany(['inter', 'code']);

    $codeIdentifier = substr(md5('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'), 0, 10);

    $this->disk()->assertExists("{$codeIdentifier}/fonts.css");

    foreach ($filesAfterInterLoad as $file) {
        $this->disk()->assertExists($file);
    }
});

it('falls back to google fonts urls in load many when fetching fails', function () {
    Http::fake(['*' => Http::response('', 500)]);

    config()->set('google-fonts.fonts', [
        'cow' => 'https://fake-url.test/cow.css',
        'dog' => 'https://fake-url.test/dog.css',
    ]);
    config()->set('google-fonts.fallback', true);

    $results = app(GoogleFonts::class)->loadMany(['cow', 'dog'], forceDownload: true);

    expect($results)->toHaveCount(2);

    $this->disk()->assertMissing('fonts.css');

    expect((string)$results[0]->fallback())->toContain('fake-url.test/cow.css')
        ->and((string)$results[1]->fallback())->toContain('fake-url.test/dog.css');
});

it('throws RuntimeException in load many when font does not exist', function () {
    expect(fn () => app(GoogleFonts::class)->loadMany(['unknown']))
        ->toThrow(RuntimeException::class, "Font `unknown` doesn't exist");
});

it('throws RuntimeException in load many when fallback is disabled and fetching fails', function () {
    Http::fake(['*' => Http::response('', 500)]);

    config()->set('google-fonts.fonts', ['cow' => 'https://fake-url.test/fonts.css']);
    config()->set('google-fonts.fallback', false);

    expect(fn () => app(GoogleFonts::class)->loadMany(['cow'], forceDownload: true))
        ->toThrow(Exception::class);
});

it('handles a single font in load many identically to load()', function () {
    $single = app(GoogleFonts::class)->load('inter', forceDownload: true);
    $many = app(GoogleFonts::class)->loadMany(['inter'], forceDownload: true)[0];

    expect($single->url())->toBe($many->url());
});

it('returns fonts in the same order as the input in load many', function () {
    config()->set('google-fonts.fonts', [
        'inter' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
        'code' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',
    ]);

    [$first, $second] = app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true);

    $interIdentifier = substr(md5('https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'), 0, 10);
    $codeIdentifier = substr(md5('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'), 0, 10);

    expect($first->url())->toContain($interIdentifier)
        ->and($second->url())->toContain($codeIdentifier);
});
