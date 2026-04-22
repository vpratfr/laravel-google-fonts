<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\GoogleFonts\Enums\FetchMode;
use Spatie\GoogleFonts\Fonts;
use Spatie\GoogleFonts\GoogleFonts;

use function Spatie\Snapshots\assertMatchesFileSnapshot;
use function Spatie\Snapshots\assertMatchesHtmlSnapshot;

it('loads google fonts from a string or array option', function (string|array $options) {
    $fonts = app(GoogleFonts::class)->load($options, forceDownload: true);

    $expectedIdentifier = substr(md5('inter'), 0, 10);
    $expectedFilePath = "$expectedIdentifier/fonts.css";

    $this->disk()->assertExists($expectedFilePath);

    assertMatchesFileSnapshot($this->disk()->path($expectedFilePath));

    $woff2FileCount = collect($this->disk()->allFiles())
        ->filter(fn (string $path) => Str::endsWith($path, '.woff2'))
        ->count();

    expect($woff2FileCount)->toBeGreaterThan(0);

    assertMatchesHtmlSnapshot((string) $fonts->link());
    assertMatchesHtmlSnapshot((string) $fonts->inline());

    expect($fonts->url())->toEqual($this->disk()->url($expectedFilePath));
})->with([
    'string option' => ['inter'],
    'array option' => [['font' => 'inter']],
]);

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

    $identifier = substr(md5('inter'), 0, 10);
    $this->disk()->put("$identifier/fonts.css", 'stale content');

    $googleFonts->load('inter', forceDownload: true);

    expect($this->disk()->get("$identifier/fonts.css"))->not->toBe('stale content');
});

it('reads preload meta from cache on second load', function () {
    config()->set('google-fonts.preload', true);

    $googleFonts = app(GoogleFonts::class);
    $googleFonts->load('inter', forceDownload: true);

    $identifier = substr(md5('inter'), 0, 10);
    $cached = $this->disk()->get("$identifier/preload.html");

    $fonts = $googleFonts->load('inter');

    expect((string) $fonts->link())->toContain($cached);
});

it('loads gracefully when preload.html does not exist in cache', function () {
    $googleFonts = app(GoogleFonts::class);
    $googleFonts->load('inter', forceDownload: true);

    $identifier = substr(md5('inter'), 0, 10);
    $this->disk()->delete("$identifier/preload.html");

    $fonts = $googleFonts->load('inter');

    expect($fonts)->toBeInstanceOf(Fonts::class);
    expect((string) $fonts->link())->toBeString();
});

it('falls back to google fonts url when fallback is enabled', function (string|array $options) {
    config()->set('google-fonts.fonts', ['cow' => ['css' => 'moo']]);
    config()->set('google-fonts.fallback', true);

    $fonts = app(GoogleFonts::class)->load($options, forceDownload: true);

    expect($this->disk()->allFiles())->toHaveCount(0);

    $fallback = <<<HTML
        <link href="moo" rel="stylesheet" type="text/css">
    HTML;

    expect([
        (string) $fonts->link(),
        (string) $fonts->inline(),
    ])->each->toEqual($fallback)
        ->and($fonts->url())->toEqual('moo');
})->with([
    'string option' => ['cow'],
    'array option' => [['font' => 'cow']],
]);

it('throws RuntimeException when font does not exist', function () {
    expect(fn () => app(GoogleFonts::class)->load('unknown'))
        ->toThrow(RuntimeException::class, "Font `unknown` doesn't exist");
});

it('throws when fetching fails and fallback is disabled', function () {
    config()->set('google-fonts.fallback', false);
    config()->set('google-fonts.fonts', ['cow' => ['css' => 'https://fake-url.test/fonts.css']]);

    Http::fake(['*' => Http::response('', 500)]);

    expect(fn () => app(GoogleFonts::class)->load('cow', forceDownload: true))
        ->toThrow(Exception::class);
});

it('adds the nonce attribute when specified', function () {
    config()->set('google-fonts.fonts', ['cow' => ['css' => 'moo']]);
    config()->set('google-fonts.fallback', true);

    $fonts = app(GoogleFonts::class)->load(['font' => 'cow', 'nonce' => 'chicken'], forceDownload: true);

    expect([
        (string) $fonts->link(),
        (string) $fonts->inline(),
    ])->each->toContain('nonce="chicken"');
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

it('can generate a font path from font name', function () {
    config()->set('google-fonts.fonts', ['cow' => ['css' => 'moo']]);

    $path = app(GoogleFonts::class)->fontPath('cow');

    expect($path)->toEqual(substr(md5('cow'), 0, 10));
});

it('keeps font path deterministic', function () {
    config()->set('google-fonts.fonts', ['cow' => ['css' => 'moo']]);

    $googleFonts = app(GoogleFonts::class);

    expect($googleFonts->fontPath('cow'))->toBe($googleFonts->fontPath('cow'));
});

it('includes the sub-path when provided to fontPath', function () {
    config()->set('google-fonts.fonts', ['cow' => ['css' => 'moo']]);

    $path = app(GoogleFonts::class)->fontPath('cow', 'fonts.css');

    expect($path)->toEndWith('/fonts.css');
});

it('generates a valid preload link tag', function () {
    $tag = app(GoogleFonts::class)->getPreload('https://example.com/font.woff2');

    expect($tag)
        ->toContain('rel="preload"')
        ->toContain('href="https://example.com/font.woff2"')
        ->toContain('as="font"')
        ->toContain('type="font/woff2"')
        ->toContain('crossorigin');
});

it('downloads ttf font when a ttf url is configured', function () {
    config()->set('google-fonts.fonts', [
        'inter' => [
            'css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700',
            'ttf' => 'https://github.com/google/fonts/raw/refs/heads/main/ofl/inter/Inter-Italic%5Bopsz%2Cwght%5D.ttf',
        ],
    ]);

    app(GoogleFonts::class)->load('inter', forceDownload: true);

    $identifier = substr(md5('inter'), 0, 10);
    $this->disk()->assertExists("$identifier/font.ttf");
});

it('skips ttf download when already cached', function () {
    $googleFonts = app(GoogleFonts::class);
    $googleFonts->load('inter', forceDownload: true);

    $identifier = substr(md5('inter'), 0, 10);
    $this->disk()->put("$identifier/font.ttf", 'cached content');

    $googleFonts->load('inter');

    expect($this->disk()->get("$identifier/font.ttf"))->toBe('cached content');
});

it('re-downloads ttf when forceDownload is true', function () {
    $googleFonts = app(GoogleFonts::class);
    $googleFonts->load('inter', forceDownload: true);

    $identifier = substr(md5('inter'), 0, 10);
    $this->disk()->put("$identifier/font.ttf", 'stale content');

    $googleFonts->load('inter', forceDownload: true);

    expect($this->disk()->get("$identifier/font.ttf"))->not->toBe('stale content');
});

it('loads multiple fonts', function (array $options) {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'],
    ]);

    $results = app(GoogleFonts::class)->loadMany($options, forceDownload: true);

    expect($results)
        ->toHaveCount(2)
        ->each->toBeInstanceOf(Fonts::class);
})->with([
    'string options' => [['inter', 'code']],
    'array options' => [[['font' => 'inter'], ['font' => 'code']]],
]);

it('persists css and woff2 files for each font in loadMany', function () {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'],
    ]);

    app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true);

    $this->disk()->assertExists(substr(md5('inter'), 0, 10) . '/fonts.css');
    $this->disk()->assertExists(substr(md5('code'), 0, 10) . '/fonts.css');

    $woff2Count = collect($this->disk()->allFiles())
        ->filter(fn (string $path) => Str::endsWith($path, '.woff2'))
        ->count();

    expect($woff2Count)->toBeGreaterThan(0);
});

it('returns fonts in the same order as the input', function () {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'],
    ]);

    [$first, $second] = app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true);

    expect($first->url())->toContain(substr(md5('inter'), 0, 10))
        ->and($second->url())->toContain(substr(md5('code'), 0, 10));
});

it('returns localized urls in loadMany, not google fonts urls', function () {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'],
    ]);

    $results = app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true);

    foreach ($results as $fonts) {
        expect($fonts->url())->not->toContain('fonts.googleapis.com');
    }
});

it('handles a single font in loadMany identically to load()', function () {
    $single = app(GoogleFonts::class)->load('inter', forceDownload: true);
    $many = app(GoogleFonts::class)->loadMany(['inter'], forceDownload: true)[0];

    expect($single->url())->toBe($many->url());
});

it('serves all fonts from local cache in loadMany without re-downloading', function () {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'],
    ]);

    $googleFonts = app(GoogleFonts::class);
    $googleFonts->loadMany(['inter', 'code'], forceDownload: true);

    $filesAfterFirstLoad = $this->disk()->allFiles();

    $googleFonts->loadMany(['inter', 'code']);

    expect($this->disk()->allFiles())->toEqual($filesAfterFirstLoad);
});

it('only re-downloads missing fonts in loadMany', function () {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'],
    ]);

    $googleFonts = app(GoogleFonts::class);
    $googleFonts->load('inter', forceDownload: true);

    $filesAfterInterLoad = $this->disk()->allFiles();

    $googleFonts->loadMany(['inter', 'code']);

    $codeIdentifier = substr(md5('code'), 0, 10);
    $this->disk()->assertExists("$codeIdentifier/fonts.css");

    foreach ($filesAfterInterLoad as $file) {
        $this->disk()->assertExists($file);
    }
});

it('preserves nonce per font in loadMany', function () {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400'],
    ]);

    [$inter, $code] = app(GoogleFonts::class)->loadMany(
        [
            ['font' => 'inter', 'nonce' => 'nonce-inter'],
            ['font' => 'code',  'nonce' => 'nonce-code'],
        ],
        forceDownload: true
    );

    expect((string) $inter->link())->toContain('nonce="nonce-inter"')
        ->and((string) $code->link())->toContain('nonce="nonce-code"');
});

it('falls back to google fonts urls in loadMany when fetching fails', function () {
    Http::fake(['*' => Http::response('', 500)]);

    config()->set('google-fonts.fonts', [
        'cow' => ['css' => 'https://fake-url.test/cow.css'],
        'dog' => ['css' => 'https://fake-url.test/dog.css'],
    ]);
    config()->set('google-fonts.fallback', true);

    $results = app(GoogleFonts::class)->loadMany(['cow', 'dog'], forceDownload: true);

    expect($results)->toHaveCount(2);

    $this->disk()->assertMissing('fonts.css');

    expect((string) $results[0]->fallback())->toContain('fake-url.test/cow.css')
        ->and((string) $results[1]->fallback())->toContain('fake-url.test/dog.css');
});

it('throws RuntimeException in loadMany when font does not exist', function () {
    expect(fn () => app(GoogleFonts::class)->loadMany(['unknown']))
        ->toThrow(RuntimeException::class, "Font `unknown` doesn't exist");
});

it('throws in loadMany when fallback is disabled and fetching fails', function () {
    Http::fake(['*' => Http::response('', 500)]);

    config()->set('google-fonts.fonts', ['cow' => ['css' => 'https://fake-url.test/fonts.css']]);
    config()->set('google-fonts.fallback', false);

    expect(fn () => app(GoogleFonts::class)->loadMany(['cow'], forceDownload: true))
        ->toThrow(Exception::class);
});

it('does not download ttf when mode is CSS', function () {
    app(GoogleFonts::class)->load('inter', forceDownload: true, mode: FetchMode::Css);

    $identifier = substr(md5('inter'), 0, 10);

    $this->disk()->assertExists("$identifier/fonts.css");
    $this->disk()->assertMissing("$identifier/font.ttf");
});

it('downloads only ttf when mode is TTF', function () {
    app(GoogleFonts::class)->load('inter', forceDownload: true, mode: FetchMode::Ttf);

    $identifier = substr(md5('inter'), 0, 10);

    $this->disk()->assertExists("$identifier/font.ttf");
    $this->disk()->assertMissing("$identifier/fonts.css");

    $woff2Count = collect($this->disk()->allFiles())
        ->filter(fn (string $path) => Str::endsWith($path, '.woff2'))
        ->count();

    expect($woff2Count)->toBe(0);
});

it('downloads both css and ttf when mode is ALL', function () {
    app(GoogleFonts::class)->load('inter', forceDownload: true, mode: FetchMode::All);

    $identifier = substr(md5('inter'), 0, 10);

    $this->disk()->assertExists("$identifier/fonts.css");
    $this->disk()->assertExists("$identifier/font.ttf");

    $woff2Count = collect($this->disk()->allFiles())
        ->filter(fn (string $path) => Str::endsWith($path, '.woff2'))
        ->count();

    expect($woff2Count)->toBeGreaterThan(0);
});

it('returns fonts without css when mode is TTF', function () {
    $fonts = app(GoogleFonts::class)->load('inter', forceDownload: true, mode: FetchMode::Ttf);

    expect((string) $fonts->link())->toContain('fonts.googleapis.com')
        ->and((string) $fonts->inline())->toContain('fonts.googleapis.com');
});

it('does not download any ttf in loadMany when mode is CSS', function () {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700', 'ttf' => 'https://github.com/google/fonts/raw/refs/heads/main/ofl/inter/Inter-Italic%5Bopsz%2Cwght%5D.ttf'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',  'ttf' => 'https://github.com/google/fonts/raw/refs/heads/main/ofl/ibmplexmono/IBMPlexMono-Regular.ttf'],
    ]);

    app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true, mode: FetchMode::Css);

    $this->disk()->assertMissing(substr(md5('inter'), 0, 10) . '/font.ttf');
    $this->disk()->assertMissing(substr(md5('code'), 0, 10) . '/font.ttf');
});

it('downloads only ttf in loadMany when mode is TTF', function () {
    config()->set('google-fonts.fonts', [
        'inter' => ['css' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700', 'ttf' => 'https://github.com/google/fonts/raw/refs/heads/main/ofl/inter/Inter-Italic%5Bopsz%2Cwght%5D.ttf'],
        'code' => ['css' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,700;1,400',  'ttf' => 'https://github.com/google/fonts/raw/refs/heads/main/ofl/ibmplexmono/IBMPlexMono-Regular.ttf'],
    ]);

    app(GoogleFonts::class)->loadMany(['inter', 'code'], forceDownload: true, mode: FetchMode::Ttf);

    $this->disk()->assertExists(substr(md5('inter'), 0, 10) . '/font.ttf');
    $this->disk()->assertExists(substr(md5('code'), 0, 10) . '/font.ttf');

    $this->disk()->assertMissing(substr(md5('inter'), 0, 10) . '/fonts.css');
    $this->disk()->assertMissing(substr(md5('code'), 0, 10) . '/fonts.css');
});
