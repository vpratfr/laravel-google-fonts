<?php

namespace Spatie\GoogleFonts\Enums;

enum FetchMode: string
{
    case All = 'all';
    case Css = 'css';
    case Ttf = 'ttf';

    public function shouldFetchCss(): bool
    {
        return $this === self::Css || $this === self::All;
    }

    public function shouldFetchTtf(): bool
    {
        return $this === self::Ttf || $this === self::All;
    }
    
}
