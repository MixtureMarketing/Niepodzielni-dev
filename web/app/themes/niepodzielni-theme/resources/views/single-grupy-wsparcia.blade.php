@extends('layouts.app')

@section('content')
  @php($post_id = get_the_ID())
  @include('partials.listing.organisms.single-workshop', [
    'post_id' => $post_id,
    'label'   => 'Grupę poprowadzi',
  ])
  @include('partials.event-reminder-form', [
    'eventId'    => $post_id,
    'eventTitle' => get_the_title($post_id),
  ])
@endsection
