@extends('layouts.app')

@section('content')
    <main id="main" class="site-main home-main">
        @include('partials.home.hero-tax')
        @include('partials.home.mission-bar')
        @include('partials.home.about-intro')
        @include('partials.home.help-grid')
        @include('partials.home.event-banner')
        @include('partials.home.team-intro')
        @include('partials.home.goals-flipboxes')
        @include('partials.home.specialists-slider')
    </main>
@endsection
