<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        {{-- Phase 11A: the sidebar is deliberately always in "dark"
             appearance (the approved color system names Deep Navy for
             "sidebar" specifically) — scoping Tailwind's dark variant
             to just this element via a literal `dark` class gives every
             Flux component inside it (nav items, the user menu) its
             already-tested, already-accessible dark styling, regardless
             of whether the rest of the page is in light or dark mode. --}}
        <flux:sidebar sticky stashable class="dark border-r border-zinc-700 bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            @php($unreadNotificationsCount = auth()->user()->unreadNotifications()->count())

            <flux:navlist variant="outline">
                <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>Dashboard</flux:navlist.item>
                <flux:navlist.item icon="bell" :href="route('notifications.index')" :current="request()->routeIs('notifications.*')" :badge="$unreadNotificationsCount > 0 ? $unreadNotificationsCount : null" badge-color="red" wire:navigate>Notifications</flux:navlist.item>

                <flux:navlist.group heading="CRM">
                    <flux:navlist.item icon="briefcase" :href="route('crm.organizations.index')" :current="request()->routeIs('crm.organizations.*')" wire:navigate>Organizations</flux:navlist.item>
                    <flux:navlist.item icon="user-circle" :href="route('crm.contacts.index')" :current="request()->routeIs('crm.contacts.*')" wire:navigate>Contacts</flux:navlist.item>
                    <flux:navlist.item icon="funnel" :href="route('crm.leads.index')" :current="request()->routeIs('crm.leads.*')" wire:navigate>Leads</flux:navlist.item>
                    <flux:navlist.item icon="currency-dollar" :href="route('crm.opportunities.index')" :current="request()->routeIs('crm.opportunities.*')" wire:navigate>Opportunities</flux:navlist.item>
                    <flux:navlist.item icon="clock" :href="route('crm.activities.index')" :current="request()->routeIs('crm.activities.*')" wire:navigate>Activities</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group heading="Performance">
                    <flux:navlist.item icon="flag" :href="route('performance.targets.index')" :current="request()->routeIs('performance.targets.*')" wire:navigate>Targets</flux:navlist.item>
                    <flux:navlist.item icon="chart-bar" :href="route('performance.index')" :current="request()->routeIs('performance.index')" wire:navigate>Performance</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group heading="Communication & AI">
                    <flux:navlist.item icon="chat-bubble-left-right" :href="route('communications.index')" :current="request()->routeIs('communications.*')" wire:navigate>Communications</flux:navlist.item>
                    <flux:navlist.item icon="sparkles" :href="route('assistant.show')" :current="request()->routeIs('assistant.*')" wire:navigate>Assistant</flux:navlist.item>
                    <flux:navlist.item icon="clock" :href="route('workflows.index')" :current="request()->routeIs('workflows.*')" wire:navigate>AI Activity</flux:navlist.item>
                    <flux:navlist.item icon="book-open" :href="route('knowledge.index')" :current="request()->routeIs('knowledge.*')" wire:navigate>Knowledge</flux:navlist.item>
                </flux:navlist.group>

                @if (auth()->user()->can('viewAny', App\Models\Team::class) || auth()->user()->can('viewAny', App\Models\User::class))
                    <flux:navlist.group heading="Organisation">
                        @can('viewAny', App\Models\Team::class)
                            <flux:navlist.item icon="users" :href="route('organisation.teams.index')" :current="request()->routeIs('organisation.teams.*')" wire:navigate>Teams</flux:navlist.item>
                        @endcan
                        @can('viewAny', App\Models\User::class)
                            <flux:navlist.item icon="identification" :href="route('organisation.users.index')" :current="request()->routeIs('organisation.users.*')" wire:navigate>Users</flux:navlist.item>
                        @endcan
                    </flux:navlist.group>
                @endif

                <flux:navlist.item icon="user" :href="route('organisation.profile')" :current="request()->routeIs('organisation.profile')" wire:navigate>Profile</flux:navlist.item>
            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
