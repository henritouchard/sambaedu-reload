@props(['colgroup'])

<div class="rounded-t-lg tablebody h-full min-h-0 flex flex-col overflow-hidden">
    <div class="w-full">
        <table class="table w-full table-fixed">
            {!! $colgroup !!}
            <thead class="highlight">
                <tr>
                    {{ $header }}
                </tr>
            </thead>
        </table>
    </div>
    <div class="overflow-y-auto flex-1 min-h-0">
        <table class="table w-full table-fixed">
            {!! $colgroup !!}
            <tbody>
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
