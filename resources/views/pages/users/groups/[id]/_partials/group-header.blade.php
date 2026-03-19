<div class="mb-6">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex items-start gap-6">
                <div class="w-14 h-14 bg-gradient-to-br from-primary to-primary/70 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-primary/20 flex-shrink-0">
                    <i class="fa-solid fa-users-rectangle text-white text-xl"></i>
                </div>
                <div class="flex-1 min-w-0 space-y-3">
                    <div>
                        <h2 class="text-2xl font-bold text-base-content">{{ $displayName ?: $name }}</h2>
                        @if ($displayName && $displayName !== $name)
                            <code class="text-sm text-base-content/50 bg-base-200 px-2 py-0.5 rounded font-mono">{{ $name }}</code>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="badge {{ $this->typeBadgeClass() }} badge-lg gap-2 px-4 py-2">
                            <i class="fa-solid fa-tag text-xs"></i>
                            {{ $this->typeLabel() }}
                        </span>
                        <span class="badge badge-outline badge-lg gap-2 px-4 py-2">
                            <i class="fa-solid fa-users text-xs"></i>
                            {{ count($this->members) }} membre(s)
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
