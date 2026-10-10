@if(session('status'))
    <div role="status" class="mb-6 rounded-xl bg-teal-50 p-4 text-sm text-teal-900">
        {{ session('status') }}
    </div>
@endif
@if($errors->any())
    <div id="form-errors" role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <p class="font-semibold">Vérifiez les informations saisies.</p>
        <ul class="mt-2 list-inside list-disc">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
