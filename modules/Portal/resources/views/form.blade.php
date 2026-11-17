@extends('portal::layout')

@section('content')
    <div class="card">
        <h1>Your reports</h1>
        <p class="muted">Enter the bill number printed on your bill and the mobile number you gave at the counter.</p>

        @if (! empty($error))<div class="error" role="alert">{{ $error }}</div>@endif
        @if ($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif

        <form method="post" action="{{ route('portal.lookup') }}" autocomplete="off">
            @csrf
            <label for="bill_no">Bill number</label>
            <input id="bill_no" name="bill_no" value="{{ $old['bill_no'] ?? old('bill_no') }}" placeholder="2611-00034" maxlength="40" required autofocus>
            <label for="mobile">Mobile number</label>
            <input id="mobile" name="mobile" type="tel" inputmode="numeric" placeholder="01XXXXXXXXX" maxlength="20" required>
            <button class="btn" type="submit">See my reports</button>
        </form>
    </div>
    <p class="muted" style="text-align:center">Your reports are private. Do not share your bill number.</p>
@endsection
