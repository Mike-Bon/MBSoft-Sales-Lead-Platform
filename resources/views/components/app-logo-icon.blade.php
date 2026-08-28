{{--
    Default / fallback company mark. Brand-neutral on purpose: a clean
    "growth" glyph, no Laravel identity. Shown only when no company logo
    has been uploaded in Company Settings (see App\Support\CompanyBranding
    and the <x-company-logo> component). Inherits colour via currentColor
    and scales with any size-*/h-*/w-* utility on the element.
--}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" {{ $attributes }}>
    <path fill="currentColor" d="M4 33a2 2 0 0 1 2-2h28a2 2 0 1 1 0 4H6a2 2 0 0 1-2-2Z" />
    <rect x="8" y="20" width="6" height="8" rx="1.5" fill="currentColor" />
    <rect x="17" y="12" width="6" height="16" rx="1.5" fill="currentColor" />
    <rect x="26" y="6" width="6" height="22" rx="1.5" fill="currentColor" />
</svg>
