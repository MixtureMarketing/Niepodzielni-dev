@props([
    'name',
    'label',
    'options'  => [],
    'value'    => '',
    'required' => false,
    'hint'     => null,
])

<div class="form-field">
    <label class="form-field__label" for="{{ $name }}">
        {!! $label !!}
        @if($required)
            <span class="form-field__required" aria-hidden="true">*</span>
        @endif
    </label>

    <select
        name="{{ $name }}"
        id="{{ $name }}"
        class="form-field__input"
        aria-invalid="false"
        aria-describedby="{{ $name }}-error{{ $hint ? ' ' . $name . '-hint' : '' }}"
        @if($required) required aria-required="true" @endif
        {{ $attributes }}
    >
        @if(!$required || !$value)
            <option value="" disabled {{ !$value ? 'selected' : '' }}>Wybierz opcję…</option>
        @endif

        @foreach($options as $val => $text)
            <option value="{{ $val }}" {{ (string)$val === (string)$value ? 'selected' : '' }}>
                {{ is_array($text) ? ($text['label'] ?? $val) : $text }}
            </option>
        @endforeach
    </select>

    @if($hint)
        <span id="{{ $name }}-hint" class="form-field__hint">{{ $hint }}</span>
    @endif

    <span id="{{ $name }}-error" class="field-error" aria-live="polite"></span>
</div>
