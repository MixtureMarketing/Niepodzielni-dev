@props([
    'name',
    'label',
    'options'  => [],
    'value'    => '',
    'required' => false,
    'hint'     => null,
])

{{-- WCAG 1.3.1 / 3.3.2 — radio group musi być w fieldset/legend --}}
<fieldset class="form-field form-field--radio">
    <legend class="form-field__label">
        {!! $label !!}
        @if($required)
            <span class="form-field__required" aria-hidden="true">*</span>
        @endif
    </legend>

    <div class="form-field__radio-group" role="radiogroup" aria-describedby="{{ $name }}-error{{ $hint ? ' ' . $name . '-hint' : '' }}">
        @foreach($options as $val => $text)
            <label class="form-field__radio-label">
                <input
                    type="radio"
                    name="{{ $name }}"
                    value="{{ $val }}"
                    class="form-field__radio"
                    {{ (string)$val === (string)$value ? 'checked' : '' }}
                    @if($required) required aria-required="true" @endif
                    {{ $attributes }}
                >
                <span>{{ $text }}</span>
            </label>
        @endforeach
    </div>

    @if($hint)
        <span id="{{ $name }}-hint" class="form-field__hint">{{ $hint }}</span>
    @endif

    <span id="{{ $name }}-error" class="field-error" aria-live="polite"></span>
</fieldset>
