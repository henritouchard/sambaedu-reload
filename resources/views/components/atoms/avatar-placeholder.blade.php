@props([
    'imageUrl' => null,
    'initials' => '',
    'color' => 'primary',
    'size' => 'size-10 ',
    'class' => 'rounded-md',
    'textSize' => 'text-xl',
])


@if ($imageUrl)
    <div class="{{ $size }} rounded-md">
        <img src="{{ $imageUrl }}" alt="Avatar de {{ $initials }}" class="w-full h-full object-cover rounded-md" />
    </div>
@else
    <div
        class="flex justify-center items-center bg-gradient-to-br from-primary to-secondary text-primary-content {{ $size }} shadow-lg ring-4 ring-primary/20 {{ $class }}">
        <span class=" {{ $textSize }} font-black">{{ strtoupper($initials ?? 'U') }}</span>
    </div>
@endif
