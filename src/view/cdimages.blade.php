{{-- DO NOT OVERRIDE OR EDIT THIS VIEW --}}
<!-- Cross domain login handling -->
@foreach($sites as $site)
    <img style="visibility: hidden; height: 0;" src="{{ $site->route('auth.internal') }}?s_code={{ $code }}"/>
@endforeach
<!-- End of cross domain login handling -->
