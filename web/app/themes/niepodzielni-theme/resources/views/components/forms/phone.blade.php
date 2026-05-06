@props([
    'name',
    'label',
    'prefixName' => null,
    'prefixes'   => [],
    'required'   => false,
    'value'      => '',
    'valuePrefix' => null,
    'placeholder' => null,
    'hint'       => null,
])

@php
    $initPrefix = $valuePrefix ?? array_key_first($prefixes) ?? '+48';
    $initMeta   = $prefixes[$initPrefix] ?? ['iso' => 'pl', 'label' => '', 'min' => 7, 'max' => 15, 'placeholder' => ''];
    $initPlaceholder = $placeholder ?? $initMeta['placeholder'] ?? '';
@endphp

<div class="form-field">
    <label class="form-field__label" for="{{ $name }}">
        {!! $label !!}
        @if($required)
            <span class="form-field__required" aria-hidden="true">*</span>
        @endif
    </label>

    <div class="form-field__phone-wrapper">
        @if($prefixName && !empty($prefixes))
        <div class="prefix-select" data-prefix-select>
            <input
                type="hidden"
                name="{{ $prefixName }}"
                value="{{ $initPrefix }}"
                data-prefix-input
            >

            <button
                type="button"
                class="prefix-select__trigger"
                aria-haspopup="listbox"
                aria-expanded="false"
                aria-label="Wybierz kierunkowy"
                data-prefix-trigger
            >
                <span class="fi fi-{{ $initMeta['iso'] }}" data-prefix-flag></span>
                <span class="prefix-select__code" data-prefix-label>{{ $initPrefix }}</span>
                <svg class="prefix-select__chevron" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                    <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>

            <div class="prefix-select__dropdown" hidden role="listbox" data-prefix-dropdown>
                <div class="prefix-select__search-wrap">
                    <input
                        type="text"
                        class="prefix-select__search"
                        placeholder="Szukaj kraju…"
                        aria-label="Szukaj kierunkowego"
                        autocomplete="off"
                        data-prefix-search
                    >
                </div>
                <ul class="prefix-select__list" data-prefix-list>
                    @foreach($prefixes as $val => $meta)
                    <li
                        class="prefix-select__option{{ $val === $initPrefix ? ' is-selected' : '' }}"
                        role="option"
                        aria-selected="{{ $val === $initPrefix ? 'true' : 'false' }}"
                        data-value="{{ $val }}"
                        data-iso="{{ $meta['iso'] }}"
                        data-label="{{ $meta['label'] }}"
                        data-min="{{ $meta['min'] }}"
                        data-max="{{ $meta['max'] }}"
                        data-placeholder="{{ $meta['placeholder'] ?? '' }}"
                        tabindex="-1"
                    >
                        <span class="fi fi-{{ $meta['iso'] }}"></span>
                        <span class="prefix-select__option-label">{{ $meta['label'] }}</span>
                        <span class="prefix-select__option-code">{{ $val }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
        @endif

        <input
            type="tel"
            name="{{ $name }}"
            id="{{ $name }}"
            class="form-field__input"
            value="{{ $value }}"
            data-mask="phone"
            minlength="{{ $initMeta['min'] }}"
            maxlength="{{ $initMeta['max'] }}"
            @if($required) required @endif
            @if($initPlaceholder) placeholder="{{ $initPlaceholder }}" @endif
            data-phone-input
            {{ $attributes }}
        >
    </div>

    @if($hint)
        <span class="form-field__hint">{{ $hint }}</span>
    @endif

    <span class="field-error" role="alert"></span>
</div>
