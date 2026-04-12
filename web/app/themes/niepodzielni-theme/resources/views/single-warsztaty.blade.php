@extends('layouts.app')

@section('content')
  @php($post_id = get_the_ID())
  @include('partials.listing.organisms.single-workshop', [
    'post_id' => $post_id,
    'label'   => 'Warsztat poprowadzi',
  ])
@endsection
