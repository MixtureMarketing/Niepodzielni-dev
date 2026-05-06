@props([
    'name',
    'label',
    'options'  => [],
    'value'    => '',
    'required' => false,
    'hint'     => null,
])

<div class="form-field">
    <span class="form-field__label">
        {!! $label !!}
        @if($required)
            <span class="form-field__required" aria-hidden="true">*</span>
        @endif
    </span>

    <div class="form-field__radio-group">
        @foreach($options as $val => $text)
            <label class="form-field__radio-label">
                <input
                    type="radio"
                    name="{{ $name }}"
                    value="{{ $val }}"
                    class="form-field__radio"
                    {{ (string)$val === (string)$value ? 'checked' : '' }}
                    @if($required) required @endif
                    {{ $attributes }}
                >
                <span>{{ $text }}</span>
            </label>
        @endforeach
    </div>

    @if($hint)
        <span class="form-field__hint">{{ $hint }}</span>
    @endif

    <span class="field-error" role="alert"></span>
</div>
