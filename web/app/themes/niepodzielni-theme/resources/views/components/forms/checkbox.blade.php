@props([
    'name',
    'label',
    'required' => false,
    'checked'  => false,
    'hint'     => null,
])

<div class="form-field form-field--checkbox">
    <label class="form-field__checkbox-label" for="{{ $name }}">
        <input
            type="checkbox"
            name="{{ $name }}"
            id="{{ $name }}"
            class="form-field__checkbox"
            value="on"
            aria-invalid="false"
            aria-describedby="{{ $name }}-error{{ $hint ? ' ' . $name . '-hint' : '' }}"
            @if($required) required aria-required="true" @endif
            @if($checked)  checked  @endif
            {{ $attributes }}
        />
        <span class="form-field__checkbox-text">
            {!! $label !!}
            @if($required)
                <span class="form-field__required" aria-hidden="true">*</span>
            @endif
        </span>
    </label>

    @if($hint)
        <span id="{{ $name }}-hint" class="form-field__hint">{{ $hint }}</span>
    @endif

    <span id="{{ $name }}-error" class="field-error" aria-live="polite"></span>
</div>
