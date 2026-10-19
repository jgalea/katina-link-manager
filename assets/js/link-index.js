( function () {
    var config = window.katinaLinkIndex;
    if ( ! config ) {
        return;
    }

    var button   = document.getElementById( 'katina-scan-start' );
    var status   = document.getElementById( 'katina-scan-status' );
    var progress = document.getElementById( 'katina-scan-progress' );

    if ( ! button ) {
        return;
    }

    function sprintf( template, a, b ) {
        return template.replace( '%1$d', a ).replace( '%2$d', b );
    }

    function runBatch( offset ) {
        var body = new FormData();
        body.append( 'action', 'katina_scan_batch' );
        body.append( 'nonce', config.nonce );
        body.append( 'offset', offset );

        fetch( config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        } )
            .then( function ( response ) {
                return response.json();
            } )
            .then( function ( payload ) {
                if ( ! payload || ! payload.success ) {
                    throw new Error( 'Scan batch failed' );
                }

                var state = payload.data;
                var total = state.total || 0;
                var done  = state.processed || 0;

                if ( total > 0 ) {
                    progress.style.display = 'inline-block';
                    progress.value = Math.round( ( done / total ) * 100 );
                }
                status.textContent = sprintf( config.strings.scanning, done, total );

                if ( state.done ) {
                    status.textContent = config.strings.done;
                    window.location.reload();
                    return;
                }

                runBatch( state.offset );
            } )
            .catch( function ( error ) {
                status.textContent = config.strings.failed;
                button.disabled = false;
                window.console.error( error );
            } );
    }

    button.addEventListener( 'click', function () {
        button.disabled = true;
        status.textContent = '';
        runBatch( 0 );
    } );
} )();
