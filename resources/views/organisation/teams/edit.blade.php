<x-layouts.app>
    <div class="w-full max-w-xl">
        <flux:heading size="xl" level="1">Edit Team</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $team->name }}</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('organisation.teams.update', $team) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <flux:input name="name" label="Name" type="text" value="{{ old('name', $team->name) }}" required />
            <flux:input name="code" label="Code" type="text" value="{{ old('code', $team->code) }}" />

            <flux:select name="status" label="Status" required>
                @foreach (\App\Enums\TeamStatus::cases() as $status)
                    <flux:select.option value="{{ $status->value }}" @selected(old('status', $team->status->value) === $status->value)>{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="{{ route('organisation.teams.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <flux:heading size="lg">Team Head</flux:heading>
        <flux:subheading class="mb-4">
            @if ($team->teamHead)
                Currently: {{ $team->teamHead->name }}. Assigning someone new keeps {{ $team->teamHead->name }} on this team as a Team Member.
            @else
                This team has no Team Head yet.
            @endif
        </flux:subheading>

        <form method="POST" action="{{ route('organisation.teams.assign-head', $team) }}" class="flex items-end gap-4">
            @csrf
            @method('PUT')

            <div class="flex-1">
                <flux:select name="team_head_id" label="Assign Team Head" placeholder="Select a user…" required>
                    @foreach ($headCandidates as $candidate)
                        <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->role->label() }})</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:button type="submit" variant="primary">Assign</flux:button>
        </form>
    </div>
</x-layouts.app>
