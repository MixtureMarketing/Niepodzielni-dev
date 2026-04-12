<section id="listing" class="psy-filters-section">
    <div class="psy-container">
        <h2 class="psy-filters-title">Znajdź swojego specjalistę</h2>
        <form id="psy-filter-form" class="psy-filters-box">
            <div class="psy-filters-row">
                <div class="filter-search-col">
                    <input type="text" id="psy-search" placeholder="Szukaj specjalisty..." class="psy-input-search">
                </div>

                @include('partials.psy-listing.molecules.filter-toggle', [
                    'name'    => 'status',
                    'options' => ['available' => 'Wolne terminy', 'all' => 'Pozostali'],
                ])

                <button type="button" id="psy-mobile-toggle-btn" class="psy-btn-mobile-filters">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                    <span>Filtry</span>
                </button>

                <div id="psy-secondary-filters" class="psy-filters-secondary">
                    @include('partials.psy-listing.molecules.filter-toggle', [
                        'name'    => 'wizyta',
                        'options' => ['' => 'Wszędzie', 'Online' => 'Online', 'Stacjonarnie' => 'Stacjonarnie'],
                    ])

                    @include('partials.psy-listing.molecules.filter-dropdown', ['tax' => 'obszar-pomocy', 'label' => 'Obszar pomocy', 'hierarchical' => true])
                    @include('partials.psy-listing.molecules.filter-dropdown', ['tax' => 'specjalizacja', 'label' => 'Specjalizacja'])
                    @include('partials.psy-listing.molecules.filter-dropdown', ['tax' => 'jezyk', 'label' => 'Język'])

                    <button type="reset" class="psy-btn-reset">Resetuj</button>
                </div>
            </div>
        </form>
        <div id="psy-listing-target"></div>
        <div id="psy-pagination-target"></div>
    </div>
</section>
