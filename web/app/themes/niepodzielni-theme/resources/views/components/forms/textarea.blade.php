@props([
    'name',
    'label',
    'value'       => '',
    'required'    => false,
    'maxlength'   => null,
    'placeholder' => null,
    'rows'        => 5,
    'hint'        => null,
])

<div class="form-field">
    <label class="form-field__label" for="{{ $name }}">
        {!! $label !!}
        @if($required)
            <span class="form-field__required" aria-hidden="true">*</span>
        @endif
    </label>

    <textarea
        name="{{ $name }}"
        id="{{ $name }}"
        class="form-field__input form-field__input--textarea"
        rows="{{ $rows }}"
        aria-invalid="false"
        aria-describedby="{{ $name }}-error{{ $hint ? ' ' . $name . '-hint' : '' }}"
        @if($required)    required aria-required="true"    @endif
        @if($maxlength)   maxlength="{{ $maxlength }}"     @endif
        @if($placeholder) placeholder="{{ $placeholder }}" @endif
        {{ $attributes }}
    >{{ $value }}</textarea>

    @if($hint)
        <span id="{{ $name }}-hint" class="form-field__hint">{{ $hint }}</span>
    @endif

    <span id="{{ $name }}-error" class="field-error" aria-live="polite"></span>
</div>
