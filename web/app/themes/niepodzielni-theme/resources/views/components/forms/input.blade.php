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
        @if($required)   required                              @endif
        @if($pattern)    pattern="{{ $pattern }}"              @endif
        @if($maxlength)  maxlength="{{ $maxlength }}"          @endif
        @if($placeholder) placeholder="{{ $placeholder }}"     @endif
        @if($errorPattern) data-error-pattern="{{ $errorPattern }}" @endif
        {{ $attributes }}
    />

    @if($hint)
        <span class="form-field__hint">{{ $hint }}</span>
    @endif

    <span class="field-error" role="alert"></span>
</div>
