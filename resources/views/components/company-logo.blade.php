{{--
    The company's logo. Renders the uploaded logo (an <img> data URI —
    never inline SVG, so an uploaded file can never inject markup or
    script) when one is configured in Company Settings, otherwise the
    brand-neutral default mark. Size it from the caller with height
    utilities, e.g. class="h-11" or class="size-8".
--}}
@php($companyLogo = \App\Support\CompanyBranding::logo())
@php($companyName = \App\Support\CompanyBranding::name())

@if ($companyLogo)
    <img
        src="{{ $companyLogo }}"
        alt="{{ $companyName }} logo"
        {{ $attributes->class(['w-auto max-w-full object-contain']) }}
    />
@else
    {{-- aspect-square keeps the square mark from collapsing when the
         caller only constrains height (w-auto on a viewBox-only SVG). --}}
    <x-app-logo-icon {{ $attributes->class(['fill-current aspect-square']) }} />
@endif
