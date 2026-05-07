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
        @if($required) required @endif
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
        <span class="form-field__hint">{{ $hint }}</span>
    @endif

    <span class="field-error" role="alert"></span>
</div>
