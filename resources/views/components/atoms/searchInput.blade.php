@props([
    'name' => true,
    'value' => false,
    "resetFilters" => null,
    'placeholder' => '',
    'icon' => '',
    'class' => '',
    'containerClass' => ''
])

<label class="bg-sky-100 p-2 rounded-full flex items-center gap-2 {{ $containerClass }} w-full">
  <i class="fa-solid {{ $icon }}"></i>
  <input {{ $attributes }} type="search" class="w-full border-none outline-none focus:outline-none focus:border-none active:outline-none active:border-none {{ $class }}" placeholder="{{ $placeholder }}" value="{{ $value }}" />
  @if ($value && $resetFilters)
    <i class="fa-solid fa-xmark cursor-pointer" onclick="{{ $resetFilters }}"></i>
  @endif
</label>