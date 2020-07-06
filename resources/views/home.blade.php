@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <h3>{{ __('Sites') }}</h3>
            @foreach($monitors as $monitor)
                <div class="row bg-white py-3 mb-1 shadow-sm">
                    <div class="col-md-4">
                        <a href="{{ __($monitor->raw_url) }}" target="_blank">{{ __($monitor->raw_url) }}</a>
                    </div>
                    <div class="col-md-4">
                        @switch($monitor->uptime_status)
                            @case('up')
                                <icon-check-circle-fill classes="text-success"></icon-check-circle-fill>
                                @break
                            @case('down')
                                <icon-x-circle-fill classes="text-danger"></icon-x-circle-fill>
                                @break
                            @case('not yet checked')
                                <icon-check-circle-fill classes="text-success"></icon-check-circle-fill>
                                @break
                        @endswitch
                        {{ __($monitor->uptime_status) }}
                    </div>
                    <div class="col-md-2">
                        <icon-clock classes="text-strong"></icon-clock>
                        {{ __($monitor->uptime_check_interval_in_minutes . 'min') }}
                    </div>
                    <div class="col-md-2 text-right">
                        <a href="#">
                            <icon-pencil-square></icon-pencil-square>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
