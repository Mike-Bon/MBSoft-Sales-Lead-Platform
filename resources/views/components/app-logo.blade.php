@php($companyHasLogo = \App\Support\CompanyBranding::hasCustomLogo())

@if ($companyHasLogo)
    {{-- A configured logo speaks for itself — show it at a readable
         size on a neutral light chip (keeps an arbitrarily-coloured
         logo legible against the always-dark Deep Navy sidebar) and
         drop the redundant name text. object-contain + max-w guarantee
         it never distorts or pushes the layout. --}}
    <span class="flex h-9 shrink-0 items-center rounded-md bg-white/95 px-2">
        <x-company-logo class="h-6 w-auto max-w-[150px]" />
    </span>
@else
    <div class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
        <x-company-logo class="size-5 text-white" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-none font-semibold">{{ \App\Support\CompanyBranding::name() }}</span>
    </div>
@endif
