@props(['name', 'label', 'value' => '', 'type' => 'text', 'hint' => null])
<div>
    <label for="{{ $name }}" class="block text-sm font-semibold">{{ $label }}</label>
    @if($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" {{ $attributes->class(['field']) }} aria-describedby="{{ $name }}-hint{{ $errors->has($name) ? ' '.$name.'-error' : '' }}" @if($errors->has($name)) aria-invalid="true" @endif>{{ old($name, $value) }}</textarea>
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" {{ $attributes->class(['field']) }} aria-describedby="{{ $name }}-hint{{ $errors->has($name) ? ' '.$name.'-error' : '' }}" @if($errors->has($name)) aria-invalid="true" @endif>
    @endif
    <p id="{{ $name }}-hint" class="mt-2 text-xs leading-relaxed text-slate-500">{{ $hint }}</p>
    @error($name)<p id="{{ $name }}-error" class="mt-2 text-sm font-medium text-red-800">{{ $message }}</p>@enderror
</div>
