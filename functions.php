<?php
require_once __DIR__ ."/database.php";


function renderAlert($message, $type = 'success', $duration = 4000)
{
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
    $safeDuration = (int) $duration;

    echo <<<HTML
    <div id="alert-top-container" style="
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 9999;
        width: 90%;
        max-width: 480px;
        pointer-events: none;
    ">
        <div class="alert alert-{$safeType} alert-dismissible fade show shadow-sm" role="alert" style="pointer-events: auto;">
            <span>{$safeMessage}</span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>

    <script>
    (function() {
        const alertBox = document.querySelector('#alert-top-container .alert');
        if (alertBox && {$safeDuration} > 0) {
            setTimeout(() => {
                if (window.bootstrap && bootstrap.Alert) {
                    bootstrap.Alert.getOrCreateInstance(alertBox).close();
                } else {
                    alertBox.classList.remove('show');
                    setTimeout(() => alertBox.remove(), 150);
                }
            }, {$safeDuration});
        }
    })();
    </script>
HTML;
}

function getInfo($conn, $id){
    


}




?>