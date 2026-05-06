@extends('layouts.app')

@section('content')
  @while(have_posts()) @php(the_post())
    <div class="page-hero">
      <div class="psy-container">
        @include('partials.page-header')
      </div>
    </div>

    <div class="page-content psy-section">
      <div class="psy-container">
        <div class="page-content__body">
          @includeFirst(['partials.content-page', 'partials.content'])
        </div>
      </div>
    </div>
  @endwhile
@endsection
