@php
    $desktop_id = function_exists('carbon_get_theme_option') ? (int) carbon_get_theme_option('hero_tax_bg_desktop') : 0;
    $mobile_id  = function_exists('carbon_get_theme_option') ? (int) carbon_get_theme_option('hero_tax_bg_mobile')  : 0;

    $hero_desktop = $desktop_id ? wp_get_attachment_url($desktop_id) : '';
    $hero_mobile  = $mobile_id  ? wp_get_attachment_url($mobile_id)  : $hero_desktop;

    $style_desktop = $hero_desktop ? "--hero-tax-bg-desktop: url('{$hero_desktop}');" : '';
    $style_mobile  = $hero_mobile  ? "--hero-tax-bg-mobile: url('{$hero_mobile}');"   : '';
@endphp
<section class="home-hero-tax" style="{{ $style_desktop }} {{ $style_mobile }}">
    <div class="psy-container">
        <div class="hero-tax-content">
            <h1 class="hero-tax-title"><b>Chcesz realnie</b><br>wesprzeć<br>działalność<br><b>Niepodzielnych?</b></h1>
            <p class="hero-tax-desc">Przekaż 1,5% na rzecz fundacji Niepodzielni!</p>
            <div class="hero-tax-actions">
                <a href="/15-2/" class="psy-btn psy-btn-green">DOWIEDZ SIĘ WIĘCEJ</a>
                <span class="hero-tax-krs">KRS: 0000270261</span>
            </div>
        </div>
    </div>
</section>
