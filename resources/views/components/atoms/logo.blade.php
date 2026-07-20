@props(['alt' => 'Logo Irundo'])

<img src="{{ asset('img/LogoSambaEdu-light.png') }}" alt="{{ $alt }}"
    {{ $attributes->merge(['class' => 'logo-theme logo-theme-light']) }} />
<img src="{{ asset('img/LogoSambaEdu-dark.png') }}" alt="{{ $alt }}" aria-hidden="true"
    {{ $attributes->merge(['class' => 'logo-theme logo-theme-dark']) }} />
