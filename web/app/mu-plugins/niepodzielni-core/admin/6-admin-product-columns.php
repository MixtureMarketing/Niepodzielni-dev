<?php
/**
 * Admin Product Columns — dodatkowe kolumny w liście psychologów w adminie
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Kolumny na liście psychologów
add_filter( 'manage_psycholog_posts_columns',       'np_psycholog_columns' );
add_action( 'manage_psycholog_posts_custom_column', 'np_psycholog_column_content', 10, 2 );

function np_psycholog_columns( array $columns ): array {
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'title' ) {
            $new['bookero_id']    = 'Bookero ID';
            $new['najblizszy']    = 'Najbliższy termin';
            $new['stawka']        = 'Stawka';
            $new['odswieztermin'] = 'Terminy';
        }
    }
    return $new;
}

function np_psycholog_column_content( string $column, int $post_id ): void {
    switch ( $column ) {
        case 'bookero_id':
            $pelny = get_post_meta( $post_id, 'bookero_id_pelny', true );
            $niski = get_post_meta( $post_id, 'bookero_id_niski', true );
            if ( $pelny ) echo '<small>Pełny: ' . esc_html( $pelny ) . '</small><br>';
            if ( $niski ) echo '<small>Niski: ' . esc_html( $niski ) . '</small>';
            break;

        case 'najblizszy':
            $pelny      = get_post_meta( $post_id, 'najblizszy_termin_pelnoplatny', true );
            $niski      = get_post_meta( $post_id, 'najblizszy_termin_niskoplatny', true );
            $updated_ts = (int) get_post_meta( $post_id, 'np_termin_updated_at', true );
            echo '<span id="np-tp-' . $post_id . '">';
            if ( $pelny ) echo '<small>Pełny: ' . esc_html( $pelny ) . '</small><br>';
            echo '</span>';
            echo '<span id="np-tn-' . $post_id . '">';
            if ( $niski ) echo '<small>Niski: ' . esc_html( $niski ) . '</small>';
            echo '</span>';
            echo '<span id="np-tu-' . $post_id . '" style="display:block;font-size:10px;color:#aaa;margin-top:3px;">';
            echo $updated_ts
                ? 'akt.: ' . esc_html( date_i18n( 'd.m.Y H:i', $updated_ts ) )
                : '<em>nie zsynchronizowano</em>';
            echo '</span>';
            break;

        case 'stawka':
            $stawka = get_post_meta( $post_id, 'stawka_wysokoplatna', true );
            echo esc_html( $stawka );
            break;

        case 'odswieztermin':
            $has_pelny = (bool) get_post_meta( $post_id, 'bookero_id_pelny', true );
            $has_niski = (bool) get_post_meta( $post_id, 'bookero_id_niski', true );
            if ( ! $has_pelny && ! $has_niski ) {
                echo '<small style="color:#999">brak ID</small>';
                break;
            }
            ?>
            <button
                type="button"
                class="button button-small np-refresh-single"
                data-post-id="<?= esc_attr( $post_id ) ?>"
                onclick="npRefreshSingle(this)"
            >Odśwież</button>
            <span class="np-refresh-status" style="display:block;font-size:11px;margin-top:3px;"></span>
            <?php
            break;
    }
}

// Skrypt obsługi przycisków — ładowany tylko na liście psychologów
add_action( 'admin_footer-edit.php', 'np_psycholog_list_scripts' );

function np_psycholog_list_scripts(): void {
    global $post_type;
    if ( $post_type !== 'psycholog' ) return;
    ?>
    <script>
    var npAjaxUrl = <?= wp_json_encode( admin_url( 'admin-ajax.php' ) ) ?>;
    var npNonce   = <?= wp_json_encode( wp_create_nonce( 'np_bookero_nonce' ) ) ?>;

    function npRefreshSingle(btn) {
        var postId  = btn.dataset.postId;
        var status  = btn.nextElementSibling;

        btn.disabled    = true;
        btn.textContent = '…';
        status.style.color   = '#666';
        status.textContent   = 'Pobieranie…';

        var body = new FormData();
        body.append('action',  'np_refresh_termin_single');
        body.append('nonce',   npNonce);
        body.append('post_id', postId);

        fetch(npAjaxUrl, { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.textContent = 'Odśwież';
                if (res.success) {
                    if (res.data.warning) {
                        status.style.color = '#b45309';
                        status.textContent = '⚠ ' + res.data.message;
                    } else {
                        status.style.color = 'green';
                        status.textContent = '✓ OK';
                    }

                    // Zaktualizuj widoczne terminy w kolumnie "Najbliższy termin"
                    var elPelny = document.getElementById('np-tp-' + postId);
                    var elNiski = document.getElementById('np-tn-' + postId);
                    if (elPelny) elPelny.innerHTML = res.data.termin_pelny !== '—'
                        ? '<small>Pełny: ' + npEsc(res.data.termin_pelny) + '</small><br>'
                        : '';
                    if (elNiski) elNiski.innerHTML = res.data.termin_niski !== '—'
                        ? '<small>Niski: ' + npEsc(res.data.termin_niski) + '</small>'
                        : '';
                    var elUpd = document.getElementById('np-tu-' + postId);
                    if (elUpd && res.data.updated_at) {
                        elUpd.textContent = 'akt.: ' + res.data.updated_at;
                    }
                } else {
                    status.style.color = 'red';
                    status.textContent = '✗ ' + (res.data && res.data.message ? res.data.message : 'Błąd');
                }
            })
            .catch(function() {
                btn.textContent = 'Odśwież';
                status.style.color = 'red';
                status.textContent = '✗ błąd sieci';
            })
            .finally(function() { btn.disabled = false; });
    }

    function npEsc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }
    </script>
    <?php
}
