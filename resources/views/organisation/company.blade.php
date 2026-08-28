<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Company Settings</flux:heading>
        <flux:subheading size="lg" class="mb-6">The company name and logo shown on the login page and in the sidebar</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <form method="POST" action="{{ route('organisation.company.update') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <flux:input
                name="name"
                label="Company name"
                value="{{ old('name', $companyName) }}"
                required
                maxlength="120"
                description="Used on the login page, the sidebar, and the browser tab title."
            />
            @error('name')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <div>
                <div class="mb-2 text-sm font-medium">Current logo</div>
                <div class="mb-3 flex h-16 items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 px-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <x-company-logo class="h-10 w-auto max-w-[200px] text-zinc-800 dark:text-zinc-100" />
                    @unless ($hasCustomLogo)
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Default mark — no company logo uploaded yet</span>
                    @endunless
                </div>

                <flux:input
                    type="file"
                    name="logo"
                    label="Upload a new logo"
                    accept="image/png,image/jpeg,image/webp"
                    description="PNG, JPG or WebP, up to 256 KB. A transparent PNG reads best on the dark sidebar."
                />
                @error('logo')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                @if ($hasCustomLogo)
                    <flux:checkbox
                        name="remove_logo"
                        value="1"
                        label="Remove the current logo and use the default mark"
                        class="mt-3"
                    />
                @endif
            </div>

            <flux:button type="submit" variant="primary">Save</flux:button>
        </form>

        <flux:callout icon="information-circle" variant="secondary" class="mt-6">
            Only the Manager can change company branding. The logo is stored with the application settings and served
            directly — no additional deployment step is required beyond running database migrations.
        </flux:callout>
    </div>
</x-layouts.app>
