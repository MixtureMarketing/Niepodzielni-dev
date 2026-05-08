@props([
    'name',
    'label',
    'type'         => 'text',
    'value'        => '',
    'required'     => false,
    'pattern'      => null,
    'errorPattern' => null,
    'autocomplete' => 'on',
    'maxlength'    => null,
    'placeholder'  => null,
    'hint'         => null,
])

<div class="form-field">
    <label class="form-field__label" for="{{ $name }}">
        {!! $label !!}
        @if($required)
            <span class="form-field__required" aria-hidden="true">*</span>
        @endif
    </label>

    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        class="form-field__input"
        value="{{ $value }}"
        autocomplete="{{ $autocomplete }}"
        aria-invalid="false"
        aria-describedby="{{ $name }}-error{{ $hint ? ' ' . $name . '-hint' : '' }}"
        @if($required)   required aria-required="true"         @endif
        @if($pattern)    pattern="{{ $pattern }}"              @endif
        @if($maxlength)  maxlength="{{ $maxlength }}"          @endif
        @if($placeholder) placeholder="{{ $placeholder }}"     @endif
        @if($errorPattern) data-error-pattern="{{ $errorPattern }}" @endif
        {{ $attributes }}
    />

    @if($hint)
        <span id="{{ $name }}-hint" class="form-field__hint">{{ $hint }}</span>
    @endif

    {{-- WCAG 4.1.3: aria-live=polite (nie role=alert) — nie spamuje SR przy każdym keystroke --}}
    <span id="{{ $name }}-error" class="field-error" aria-live="polite"></span>
</div>
