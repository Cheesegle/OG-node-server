<?php
if(isset($_GET['check']) && !empty($_GET['check']) && strlen($_GET['check']) >= 10 )
    die(md5('magenet.com'));
?>