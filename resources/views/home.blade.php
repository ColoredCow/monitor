@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <h3>{{ __('Site monitoring') }}</h3>
            @foreach($monitors as $monitor)
                <div class="row bg-white py-3 shadow-sm">
                    <div class="col-4">
                        <a href="{{ __($monitor->raw_url) }}" target="_blank">{{ __($monitor->raw_url) }}</a>
                    </div>
                    <div class="col-4">
                        {{ __($monitor->uptime_status) }}
                    </div>
                    <div class="col-2">
                        {{ __($monitor->uptime_check_interval_in_minutes . 'min') }}
                    </div>
                    <div class="col-2">
                        {{ __('Edit') }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
