@props(['value'])
<time datetime="{{ $value->toIso8601String() }}" title="Heure de Paris">{{ $value->copy()->setTimezone('Europe/Paris')->format('d/m/Y · H:i') }}</time>
